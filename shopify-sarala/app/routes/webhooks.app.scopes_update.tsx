import type { ActionFunctionArgs } from "react-router";
import { authenticate, sessionStorageInstance } from "../shopify.server";

export const action = async ({ request }: ActionFunctionArgs) => {
  const { payload, session } = await authenticate.webhook(request);
  const current = payload.current as string[] | undefined;
  if (session && current && sessionStorageInstance) {
    session.scope = current.join(",");
    await sessionStorageInstance.storeSession(session);
  }
  return new Response();
};
