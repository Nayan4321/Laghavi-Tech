import type { ActionFunctionArgs } from "react-router";
import { authenticate } from "../shopify.server";
import { setActive } from "../lib/shop-data.server";

/**
 * The plan changed. Without an active plan the storefront lock and account blocks switch off, so a
 * store is never left locked because a payment failed.
 */
export const action = async ({ request }: ActionFunctionArgs) => {
  const { payload, admin } = await authenticate.webhook(request);
  const status = String((payload.app_subscription as { status?: string } | undefined)?.status ?? "").toUpperCase();
  if (admin && status) {
    await setActive(admin.graphql, status === "ACTIVE");
  }
  return new Response();
};
