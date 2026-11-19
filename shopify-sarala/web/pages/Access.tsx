import { useEffect, useState } from "react";
import { type AccessConfig, DEFAULT_CART_SELECTORS, DEFAULT_PRICE_SELECTORS, PENDING_TAG } from "../lib/config";
import { useData, useFetcher } from "../api";

type AccessData = { access: AccessConfig };
type AccessResult = { ok: boolean; error: string; access: AccessConfig };

type Form = Omit<AccessConfig, "lockedPages" | "lockedCollections" | "lockedProducts" | "allowedTags"> & {
  lockedPages: string;
  lockedCollections: string;
  lockedProducts: string;
  allowedTags: string;
};

const toForm = (a: AccessConfig): Form => ({
  ...a,
  lockedPages: a.lockedPages.join("\n"),
  lockedCollections: a.lockedCollections.join("\n"),
  lockedProducts: a.lockedProducts.join("\n"),
  allowedTags: a.allowedTags.join(", "),
});

export default function Access() {
  const { access } = useData<AccessData>();
  const fetcher = useFetcher<AccessResult>("/api/access");
  const [f, setF] = useState<Form>(() => toForm(access));
  const set = (patch: Partial<Form>) => setF((x) => ({ ...x, ...patch }));
  const saving = fetcher.state !== "idle";

  useEffect(() => {
    if (fetcher.state === "idle" && fetcher.data) {
      if (fetcher.data.ok) {
        setF(toForm(fetcher.data.access));
        shopify.toast.show("Access settings saved");
      } else {
        shopify.toast.show(fetcher.data.error, { isError: true });
      }
    }
  }, [fetcher.state, fetcher.data]);

  const save = () => fetcher.submit(f, { method: "post", encType: "application/json" });

  return (
    <s-page heading="Access & approval">
      <s-button slot="primary-action" variant="primary" onClick={save} loading={saving || undefined}>
        Save
      </s-button>

      <s-section heading="Approve new accounts">
        <s-stack gap="base">
          <s-checkbox
            label="New customer accounts wait for my approval"
            checked={f.approval || undefined}
            details={`New customers get the tag “${PENDING_TAG}”. Until you approve them in Customers to review, they are treated like guests: locked pages stay locked and hidden prices stay hidden. Their account page shows your waiting message.`}
            onChange={(e) => set({ approval: e.currentTarget.checked })}
          />
        </s-stack>
      </s-section>

      <s-section heading="Members only">
        <s-stack gap="base">
          <s-paragraph>Locked pages ask visitors to sign in. Signed-in, approved customers see them as usual.</s-paragraph>
          <s-checkbox label="Lock the whole store" checked={f.storeLock || undefined} details="Every page asks visitors to sign in, except the sign-in, account, policy and password pages." onChange={(e) => set({ storeLock: e.currentTarget.checked })} />
          <s-grid gridTemplateColumns="1fr 1fr 1fr" gap="base">
            <s-text-area label="Lock these pages" rows={4} value={f.lockedPages} details="Page addresses or handles, one per line: wholesale or /pages/wholesale." onInput={(e) => set({ lockedPages: e.currentTarget.value })} />
            <s-text-area label="Lock these collections" rows={4} value={f.lockedCollections} details="Collection handles, one per line." onInput={(e) => set({ lockedCollections: e.currentTarget.value })} />
            <s-text-area label="Lock these products" rows={4} value={f.lockedProducts} details="Product handles, one per line." onInput={(e) => set({ lockedProducts: e.currentTarget.value })} />
          </s-grid>
          <s-text-field label="Only customers with these tags (optional)" value={f.allowedTags} details="For example: Wholesale, VIP. Empty: every signed-in, approved customer." onInput={(e) => set({ allowedTags: e.currentTarget.value })} />
          <s-checkbox label="Hide prices and the add-to-cart button from guests" checked={f.hidePrices || undefined} details="Guests and customers waiting for approval see “Sign in to see prices” instead." onChange={(e) => set({ hidePrices: e.currentTarget.checked })} />
          <s-grid gridTemplateColumns="2fr 1fr" gap="base">
            <s-text-area label="Message on locked pages" rows={2} value={f.lockMessage} onInput={(e) => set({ lockMessage: e.currentTarget.value })} />
            <s-text-field label="Button" value={f.lockButton} onInput={(e) => set({ lockButton: e.currentTarget.value })} />
          </s-grid>
          <s-banner tone="info">
            Sarala hides locked content and prices on your storefront for visitors who are not signed in. Search engines and visitors can still reach product data in other ways (for example the product’s .json address), so don’t put secrets on locked pages.
          </s-banner>
        </s-stack>
      </s-section>

      <s-section heading="Sign-in prompt">
        <s-stack gap="base">
          <s-checkbox label="Show guests a sign-in bar" checked={f.prompt || undefined} details="A slim bar at the top of the store inviting guests to sign in." onChange={(e) => set({ prompt: e.currentTarget.checked })} />
          <s-grid gridTemplateColumns="2fr 1fr" gap="base">
            <s-text-field label="Text" value={f.promptText} onInput={(e) => set({ promptText: e.currentTarget.value })} />
            <s-text-field label="Button" value={f.promptButton} onInput={(e) => set({ promptButton: e.currentTarget.value })} />
          </s-grid>
        </s-stack>
      </s-section>

      <s-section heading="After signing in">
        <s-stack gap="base">
          <s-paragraph>Send customers to a page right after they sign in, by tag. The first rule that matches is used; leave the tag empty to match everyone.</s-paragraph>
          {f.redirects.map((r, i) => (
            <s-grid key={i} gridTemplateColumns="1fr 2fr auto" gap="small-300" alignItems="end">
              <s-text-field
                label="Customers with the tag"
                value={r.tag}
                onInput={(e) => {
                  const redirects = [...f.redirects];
                  redirects[i] = { ...r, tag: e.currentTarget.value };
                  set({ redirects });
                }}
              />
              <s-text-field
                label="Go to"
                value={r.url}
                placeholder="/collections/wholesale"
                onInput={(e) => {
                  const redirects = [...f.redirects];
                  redirects[i] = { ...r, url: e.currentTarget.value };
                  set({ redirects });
                }}
              />
              <s-button variant="tertiary" tone="critical" onClick={() => set({ redirects: f.redirects.filter((_, x) => x !== i) })}>
                Remove
              </s-button>
            </s-grid>
          ))}
          <s-stack direction="inline">
            <s-button variant="secondary" onClick={() => set({ redirects: [...f.redirects, { tag: "", url: "" }] })}>Add a rule</s-button>
          </s-stack>
        </s-stack>
      </s-section>

      <s-section heading="Advanced: your theme">
        <s-stack gap="base">
          <s-paragraph>Sarala finds prices and add-to-cart buttons in most themes. If your theme uses other names, enter its CSS selectors here.</s-paragraph>
          <s-text-field label="Prices" value={f.priceSelectors} placeholder={DEFAULT_PRICE_SELECTORS} onInput={(e) => set({ priceSelectors: e.currentTarget.value })} />
          <s-text-field label="Add-to-cart buttons" value={f.cartSelectors} placeholder={DEFAULT_CART_SELECTORS} onInput={(e) => set({ cartSelectors: e.currentTarget.value })} />
        </s-stack>
      </s-section>
    </s-page>
  );
}

