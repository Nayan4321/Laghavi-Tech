import type { ActionFunctionArgs } from "react-router";
import { authenticate } from "../shopify.server";
import { PENDING_TAG, SUSPICIOUS_TAG } from "../lib/config";
import { changeTags, getInstallation } from "../lib/shop-data.server";
import { checkSignup } from "../lib/signup-check";

/** A new customer: wait for approval when approval is on, and flag likely fake sign-ups. */
export const action = async ({ request }: ActionFunctionArgs) => {
  const { payload, admin } = await authenticate.webhook(request);
  if (!admin) return new Response();
  const inst = await getInstallation(admin.graphql);
  if (!inst.access.active) return new Response();
  const id = String(payload.admin_graphql_api_id ?? "");
  if (!id) return new Response();
  const tags = String(payload.tags ?? "")
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);
  const add: string[] = [];
  if (inst.access.approval && !tags.includes(PENDING_TAG)) add.push(PENDING_TAG);
  const check = checkSignup({
    email: (payload.email as string) ?? null,
    firstName: (payload.first_name as string) ?? null,
    lastName: (payload.last_name as string) ?? null,
  });
  if (check.suspicious) add.push(SUSPICIOUS_TAG);
  if (add.length) await changeTags(admin.graphql, id, add, []);
  return new Response();
};
