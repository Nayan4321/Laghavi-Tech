import { useEffect, useState } from "react";
import type { ActionFunctionArgs, HeadersFunction, LoaderFunctionArgs } from "react-router";
import { type SubmitTarget, useFetcher, useLoaderData } from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate } from "../shopify.server";
import { type Announcement, cleanAccountConfig } from "../lib/config";
import { ShopifyError, getShop, saveAccount } from "../lib/shop-data.server";

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { admin } = await authenticate.admin(request);
  const shop = await getShop(admin.graphql);
  return { account: shop.account };
};

export const action = async ({ request }: ActionFunctionArgs) => {
  const { admin } = await authenticate.admin(request);
  const body = (await request.json()) as { pendingMessage?: unknown; announcements?: unknown };
  const shop = await getShop(admin.graphql);
  const account = cleanAccountConfig({ ...shop.account, pendingMessage: body.pendingMessage, announcements: body.announcements });
  try {
    await saveAccount(admin.graphql, shop.id, account);
  } catch (e) {
    return { ok: false, error: e instanceof ShopifyError ? e.message : "Could not save. Please try again.", account: shop.account };
  }
  return { ok: true, error: "", account };
};

export default function Account() {
  const { account } = useLoaderData<typeof loader>();
  const fetcher = useFetcher<typeof action>();
  const [pending, setPending] = useState(account.pendingMessage);
  const [items, setItems] = useState<Announcement[]>(account.announcements);
  const saving = fetcher.state !== "idle";

  useEffect(() => {
    if (fetcher.state === "idle" && fetcher.data) {
      if (fetcher.data.ok) {
        setItems(fetcher.data.account.announcements);
        shopify.toast.show("Account page saved");
      } else {
        shopify.toast.show(fetcher.data.error, { isError: true });
      }
    }
  }, [fetcher.state, fetcher.data]);

  const save = () =>
    fetcher.submit({ pendingMessage: pending, announcements: items } as unknown as SubmitTarget, { method: "post", encType: "application/json" });
  const update = (i: number, patch: Partial<Announcement>) => setItems((all) => all.map((a, x) => (x === i ? { ...a, ...patch } : a)));

  return (
    <s-page heading="Account page">
      <s-button slot="primary-action" variant="primary" onClick={save} loading={saving || undefined}>
        Save
      </s-button>
      <s-section heading="Waiting for approval">
        <s-text-area
          label="Message for customers waiting for approval"
          rows={3}
          value={pending}
          details="Shown at the top of their account while approval is on and they are not approved yet."
          onInput={(e) => setPending(e.currentTarget.value)}
        />
      </s-section>
      <s-section heading="Messages for customers">
        <s-stack gap="base">
          <s-paragraph>Show a message at the top of the customer's account: a welcome note, a members' offer, or news for one group (by tag).</s-paragraph>
          {items.map((a, i) => (
            <s-box key={i} padding="base" borderWidth="base" borderRadius="base">
              <s-stack gap="small-300">
                <s-grid gridTemplateColumns="1fr 2fr" gap="base">
                  <s-text-field label="Show to customers with the tag" value={a.tag} details="Empty: everyone." onInput={(e) => update(i, { tag: e.currentTarget.value })} />
                  <s-text-field label="Title" value={a.title} onInput={(e) => update(i, { title: e.currentTarget.value })} />
                </s-grid>
                <s-text-area label="Message" rows={2} value={a.text} onInput={(e) => update(i, { text: e.currentTarget.value })} />
                <s-stack direction="inline">
                  <s-button variant="tertiary" tone="critical" onClick={() => setItems((all) => all.filter((_, x) => x !== i))}>Remove</s-button>
                </s-stack>
              </s-stack>
            </s-box>
          ))}
          <s-stack direction="inline">
            <s-button variant="secondary" onClick={() => setItems((all) => [...all, { tag: "", title: "", text: "" }])}>Add a message</s-button>
          </s-stack>
        </s-stack>
      </s-section>
    </s-page>
  );
}

export const headers: HeadersFunction = (headersArgs) => boundary.headers(headersArgs);
