// Shared helpers: everything is read from and saved to Shopify through the Customer Account API.
export const APP_URL = "https://sarala.laghavi.site";
export const PENDING_TAG = "sarala-pending";
const ENDPOINT = "shopify://customer-account/api/2026-10/graphql.json";

export async function cag(query, variables) {
  const res = await fetch(ENDPOINT, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ query, variables }),
  });
  const body = await res.json();
  if (body.errors || !body.data) throw new Error("Shopify error");
  return body.data;
}

export async function loadAccount() {
  const data = await cag(`query SaralaConfig {
    shop { metafield(namespace: "$app", key: "account") { value } }
    customer { id tags }
  }`);
  let config = null;
  try {
    config = data.shop.metafield ? JSON.parse(data.shop.metafield.value) : null;
  } catch (e) {
    config = null;
  }
  return { config, customer: data.customer };
}

export const hasTag = (tags, tag) => tags.some((t) => t.toLowerCase() === tag.toLowerCase());
