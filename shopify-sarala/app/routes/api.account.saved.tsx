import type { ActionFunctionArgs, LoaderFunctionArgs } from "react-router";
import { authenticate, unauthenticated } from "../shopify.server";
import { answerKey, tagsFromAnswers } from "../lib/config";
import { changeTags, getCustomerAnswers, getShop } from "../lib/shop-data.server";

/**
 * Called by the account page block right after a customer saved their answers. The session token
 * proves which customer of which shop is calling; the app then adds the tags their answers earn and
 * removes the app's tags they no longer earn. Tags the merchant added by hand are never touched.
 */
async function handle(request: Request) {
  const { sessionToken, cors } = await authenticate.public.customerAccount(request);
  if (request.method !== "POST") return cors(Response.json({ ok: true }));
  const shopDomain = String(sessionToken.dest ?? "").replace(/^https?:\/\//, "");
  const sub = String(sessionToken.sub ?? "");
  const customerId = sub.startsWith("gid://") ? sub : /^\d+$/.test(sub) ? `gid://shopify/Customer/${sub}` : "";
  if (!shopDomain || !customerId) return cors(Response.json({ ok: false }, { status: 400 }));

  const { admin } = await unauthenticated.admin(shopDomain);
  const shop = await getShop(admin.graphql);
  if (!shop.account.active) return cors(Response.json({ ok: true, tags: [] }));
  const fields = shop.account.fields;
  const customer = await getCustomerAnswers(admin.graphql, customerId, fields.map(answerKey));
  if (!customer) return cors(Response.json({ ok: false }, { status: 404 }));

  const { add, managed } = tagsFromAnswers(fields, customer.answers);
  const toAdd = add.filter((t) => !customer.tags.includes(t));
  const toRemove = managed.filter((t) => !add.includes(t) && customer.tags.includes(t));
  await changeTags(admin.graphql, customerId, toAdd, toRemove);
  return cors(Response.json({ ok: true }));
}

export const loader = ({ request }: LoaderFunctionArgs) => handle(request);
export const action = ({ request }: ActionFunctionArgs) => handle(request);
