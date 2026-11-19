/**
 * Everything the app reads and writes in the shop, through the Admin GraphQL API.
 */
import type { AdminApiContext } from "@shopify/shopify-app-react-router/server";
import {
  type AccessConfig,
  type AccountConfig,
  type FieldDef,
  METAFIELD_TYPES,
  answerKey,
  cleanAccessConfig,
  cleanAccountConfig,
} from "./config";

type Admin = AdminApiContext["graphql"];

export class ShopifyError extends Error {}

async function gql<T>(graphql: Admin, query: string, variables?: Record<string, unknown>): Promise<T> {
  const res = await graphql(query, variables ? { variables } : undefined);
  const body = (await res.json()) as { data?: T; errors?: unknown };
  if (body.errors || !body.data) {
    throw new ShopifyError("Shopify returned an error: " + JSON.stringify(body.errors ?? "no data").slice(0, 300));
  }
  return body.data;
}

function userErrors(errors: { message: string; code?: string | null }[] | undefined, allow: string[] = []) {
  const real = (errors ?? []).filter((e) => !allow.includes(e.code ?? ""));
  if (real.length) throw new ShopifyError(real.map((e) => e.message).join(" "));
}

/* App installation: access settings and the subscription. */

export interface Installation {
  id: string;
  access: AccessConfig;
  subscribed: boolean;
}

export async function getInstallation(graphql: Admin): Promise<Installation> {
  const data = await gql<{
    currentAppInstallation: {
      id: string;
      metafield: { value: string } | null;
      activeSubscriptions: { status: string }[];
    };
  }>(
    graphql,
    `#graphql
    query SaralaInstallation {
      currentAppInstallation {
        id
        metafield(namespace: "sarala", key: "access") { value }
        activeSubscriptions { status }
      }
    }`,
  );
  const inst = data.currentAppInstallation;
  let access = cleanAccessConfig(null);
  try {
    access = cleanAccessConfig(inst.metafield ? JSON.parse(inst.metafield.value) : null);
  } catch {
    // Keep the defaults when the saved value cannot be read.
  }
  return {
    id: inst.id,
    access,
    subscribed: inst.activeSubscriptions.some((s) => s.status === "ACTIVE"),
  };
}

export async function saveAccess(graphql: Admin, installationId: string, access: AccessConfig) {
  const data = await gql<{ metafieldsSet: { userErrors: { message: string; code: string | null }[] } }>(
    graphql,
    `#graphql
    mutation SaralaSaveAccess($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) { userErrors { message code } }
    }`,
    {
      metafields: [
        { ownerId: installationId, namespace: "sarala", key: "access", type: "json", value: JSON.stringify(access) },
      ],
    },
  );
  userErrors(data.metafieldsSet.userErrors);
}

/* Shop: account page settings (fields, messages), read by the account blocks. */

export interface ShopInfo {
  id: string;
  name: string;
  myshopifyDomain: string;
  account: AccountConfig;
}

export async function getShop(graphql: Admin): Promise<ShopInfo> {
  const data = await gql<{
    shop: { id: string; name: string; myshopifyDomain: string; metafield: { value: string } | null };
  }>(
    graphql,
    `#graphql
    query SaralaShop {
      shop {
        id
        name
        myshopifyDomain
        metafield(namespace: "$app", key: "account") { value }
      }
    }`,
  );
  let account = cleanAccountConfig(null);
  try {
    account = cleanAccountConfig(data.shop.metafield ? JSON.parse(data.shop.metafield.value) : null);
  } catch {
    // Defaults.
  }
  return { id: data.shop.id, name: data.shop.name, myshopifyDomain: data.shop.myshopifyDomain, account };
}

const DEFINITION_CREATE = `#graphql
  mutation SaralaDefinition($definition: MetafieldDefinitionInput!) {
    metafieldDefinitionCreate(definition: $definition) {
      createdDefinition { id }
      userErrors { message code }
    }
  }`;

/**
 * The definitions that let the account blocks read the settings and read/write each answer, and
 * show every answer on the customer's page in the Shopify admin.
 */
export async function syncDefinitions(graphql: Admin, fields: FieldDef[]) {
  const defs = [
    {
      name: "Sarala account settings",
      namespace: "$app",
      key: "account",
      type: "json",
      ownerType: "SHOP",
      access: { admin: "MERCHANT_READ", customerAccount: "READ" },
    },
    ...fields.map((f) => ({
      name: f.label,
      namespace: "$app",
      key: answerKey(f),
      type: METAFIELD_TYPES[f.type],
      ownerType: "CUSTOMER",
      description: "Answer collected by Sarala User Access",
      access: { admin: "MERCHANT_READ_WRITE", customerAccount: "READ_WRITE" },
    })),
  ];
  for (const definition of defs) {
    const data = await gql<{ metafieldDefinitionCreate: { userErrors: { message: string; code: string | null }[] } }>(
      graphql,
      DEFINITION_CREATE,
      { definition },
    );
    // TAKEN: it exists already from an earlier save.
    userErrors(data.metafieldDefinitionCreate.userErrors, ["TAKEN"]);
  }
}

export async function saveAccount(graphql: Admin, shopId: string, account: AccountConfig) {
  await syncDefinitions(graphql, account.fields);
  const data = await gql<{ metafieldsSet: { userErrors: { message: string; code: string | null }[] } }>(
    graphql,
    `#graphql
    mutation SaralaSaveAccount($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) { userErrors { message code } }
    }`,
    { metafields: [{ ownerId: shopId, namespace: "$app", key: "account", type: "json", value: JSON.stringify(account) }] },
  );
  userErrors(data.metafieldsSet.userErrors);
}

/** Switch the storefront and account parts on or off (off when there is no active plan). */
export async function setActive(graphql: Admin, active: boolean) {
  const inst = await getInstallation(graphql);
  const shop = await getShop(graphql);
  if (inst.access.active !== active) await saveAccess(graphql, inst.id, { ...inst.access, active });
  if (shop.account.active !== active) await saveAccount(graphql, shop.id, { ...shop.account, active });
}

/* Customers. */

export interface CustomerRow {
  id: string;
  name: string;
  email: string;
  createdAt: string;
  tags: string[];
}

export async function listCustomers(graphql: Admin, query: string, first = 50): Promise<CustomerRow[]> {
  const data = await gql<{
    customers: {
      nodes: { id: string; displayName: string; defaultEmailAddress: { emailAddress: string } | null; createdAt: string; tags: string[] }[];
    };
  }>(
    graphql,
    `#graphql
    query SaralaCustomers($query: String!, $first: Int!) {
      customers(first: $first, query: $query, sortKey: CREATED_AT, reverse: true) {
        nodes { id displayName defaultEmailAddress { emailAddress } createdAt tags }
      }
    }`,
    { query, first },
  );
  return data.customers.nodes.map((c) => ({
    id: c.id,
    name: c.displayName,
    email: c.defaultEmailAddress?.emailAddress ?? "",
    createdAt: c.createdAt,
    tags: c.tags,
  }));
}

export async function countCustomers(graphql: Admin, query: string): Promise<number> {
  const data = await gql<{ customersCount: { count: number } }>(
    graphql,
    `#graphql
    query SaralaCount($query: String!) { customersCount(query: $query) { count } }`,
    { query },
  );
  return data.customersCount.count;
}

export async function changeTags(graphql: Admin, id: string, add: string[], remove: string[]) {
  if (remove.length) {
    const data = await gql<{ tagsRemove: { userErrors: { message: string }[] } }>(
      graphql,
      `#graphql
      mutation SaralaTagsRemove($id: ID!, $tags: [String!]!) { tagsRemove(id: $id, tags: $tags) { userErrors { message } } }`,
      { id, tags: remove },
    );
    userErrors(data.tagsRemove.userErrors);
  }
  if (add.length) {
    const data = await gql<{ tagsAdd: { userErrors: { message: string }[] } }>(
      graphql,
      `#graphql
      mutation SaralaTagsAdd($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { userErrors { message } } }`,
      { id, tags: add },
    );
    userErrors(data.tagsAdd.userErrors);
  }
}

export async function deleteCustomer(graphql: Admin, id: string) {
  const data = await gql<{ customerDelete: { userErrors: { message: string }[] } }>(
    graphql,
    `#graphql
    mutation SaralaDelete($id: ID!) { customerDelete(input: { id: $id }) { userErrors { message } } }`,
    { id },
  );
  userErrors(data.customerDelete.userErrors);
}

/** A customer's tags and Sarala answers. */
export async function getCustomerAnswers(graphql: Admin, id: string, keys: string[]) {
  const data = await gql<{
    customer: { tags: string[]; metafields: { nodes: { key: string; value: string }[] } } | null;
  }>(
    graphql,
    `#graphql
    query SaralaCustomer($id: ID!) {
      customer(id: $id) {
        tags
        metafields(first: 50, namespace: "$app") { nodes { key value } }
      }
    }`,
    { id },
  );
  if (!data.customer) return null;
  const answers: Record<string, string> = {};
  for (const m of data.customer.metafields.nodes) if (keys.includes(m.key)) answers[m.key] = m.value;
  return { tags: data.customer.tags, answers };
}
