import type { ActionFunctionArgs } from "react-router";
import { authenticate, sessionStorageInstance } from "../shopify.server";

/**
 * Shopify's privacy webhooks.
 *
 * The app keeps no customer data of its own: customers' answers are metafields stored in the shop,
 * which the merchant controls, and the app's database holds only the shop's login sessions.
 * - customers/data_request: nothing to report beyond what is already in the shop.
 * - customers/redact: remove the customer's Sarala answers from the shop.
 * - shop/redact: delete the shop's sessions.
 */
export const action = async ({ request }: ActionFunctionArgs) => {
  const { topic, shop, payload, admin } = await authenticate.webhook(request);
  switch (topic) {
    case "CUSTOMERS_REDACT": {
      const customerId = (payload.customer as { id?: number } | undefined)?.id;
      if (admin && customerId) {
        const owner = `gid://shopify/Customer/${customerId}`;
        const res = await admin.graphql(
          `#graphql
          query SaralaRedact($id: ID!) { customer(id: $id) { metafields(first: 50, namespace: "$app") { nodes { key } } } }`,
          { variables: { id: owner } },
        );
        const body = (await res.json()) as { data?: { customer?: { metafields: { nodes: { key: string }[] } } | null } };
        const keys = body.data?.customer?.metafields.nodes.map((n) => n.key) ?? [];
        if (keys.length) {
          await admin.graphql(
            `#graphql
            mutation SaralaRedactDelete($metafields: [MetafieldIdentifierInput!]!) { metafieldsDelete(metafields: $metafields) { userErrors { message } } }`,
            { variables: { metafields: keys.map((key) => ({ ownerId: owner, namespace: "$app", key })) } },
          );
        }
      }
      break;
    }
    case "SHOP_REDACT": {
      if (sessionStorageInstance) {
        const sessions = await sessionStorageInstance.findSessionsByShop(shop);
        await sessionStorageInstance.deleteSessions(sessions.map((s) => s.id));
      }
      break;
    }
    default:
      // CUSTOMERS_DATA_REQUEST: the app stores no customer data outside the shop.
      break;
  }
  return new Response();
};
