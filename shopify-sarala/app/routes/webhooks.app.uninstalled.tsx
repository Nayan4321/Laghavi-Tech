import type { ActionFunctionArgs } from "react-router";
import { authenticate, sessionStorageInstance } from "../shopify.server";

/** The app was removed: forget the shop's sessions. Shopify removes the app's metafields and theme embed. */
export const action = async ({ request }: ActionFunctionArgs) => {
  const { shop, session } = await authenticate.webhook(request);
  if (session && sessionStorageInstance) {
    const sessions = await sessionStorageInstance.findSessionsByShop(shop);
    await sessionStorageInstance.deleteSessions(sessions.map((s) => s.id));
  }
  return new Response();
};
