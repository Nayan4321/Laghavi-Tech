import type { HeadersFunction, LoaderFunctionArgs } from "react-router";
import { useLoaderData } from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate } from "../shopify.server";
import { APPROVED_TAG, PENDING_TAG, SUSPICIOUS_TAG } from "../lib/config";
import { countCustomers, getInstallation, getShop, setActive } from "../lib/shop-data.server";

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { admin, session } = await authenticate.admin(request);
  const inst = await getInstallation(admin.graphql);
  // The parent route only lets merchants with an active plan in, so switch everything on.
  if (inst.subscribed && !inst.access.active) await setActive(admin.graphql, true);
  const shop = await getShop(admin.graphql);
  const since = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);
  const [signups, pending, approved, suspicious] = await Promise.all([
    countCustomers(admin.graphql, `created_at:>='${since}'`),
    countCustomers(admin.graphql, `tag:'${PENDING_TAG}'`),
    countCustomers(admin.graphql, `tag:'${APPROVED_TAG}'`),
    countCustomers(admin.graphql, `tag:'${SUSPICIOUS_TAG}'`),
  ]);
  const store = session.shop.replace(/\.myshopify\.com$/, "");
  return {
    store,
    apiKey: process.env.SHOPIFY_API_KEY || "",
    fields: shop.account.fields.length,
    access: inst.access,
    stats: { signups, pending, approved, suspicious },
  };
};

function Stat({ label, value, href }: { label: string; value: number; href?: string }) {
  return (
    <s-box padding="base" borderWidth="base" borderRadius="base">
      <s-stack gap="small-200">
        <s-text color="subdued">{label}</s-text>
        <s-heading>{value.toLocaleString()}</s-heading>
        {href && <s-link href={href}>Review</s-link>}
      </s-stack>
    </s-box>
  );
}

export default function Home() {
  const { store, apiKey, fields, access, stats } = useLoaderData<typeof loader>();
  const themeEditor = `https://admin.shopify.com/store/${store}/themes/current/editor?context=apps&activateAppId=${apiKey}/sarala-access`;
  const accountEditor = `https://admin.shopify.com/store/${store}/settings/checkout`;
  const steps = [
    { done: fields > 0, title: "Add sign-up and profile fields", text: "Ask customers for their birthday, company, interests or anything else. Answers are saved on the customer in Shopify.", href: "/app/fields", action: "Add fields" },
    { done: false, title: "Turn on Sarala in your theme", text: "In the theme editor, under App embeds, switch on “Sarala access”. It locks pages, hides prices and shows sign-in prompts.", href: themeEditor, action: "Open the theme editor", external: true },
    { done: false, title: "Add the Sarala blocks to customer accounts", text: "In Settings › Checkout and accounts › Customize, add “Sarala profile fields” to the Profile page and “Sarala messages” to the Orders page.", href: accountEditor, action: "Open the accounts editor", external: true },
    { done: access.approval || access.storeLock || access.lockedPages.length + access.lockedProducts.length + access.lockedCollections.length > 0 || access.hidePrices, title: "Choose who can see what", text: "Approve new accounts, lock pages or the whole store, and hide prices from guests.", href: "/app/access", action: "Set up access" },
  ];
  return (
    <s-page heading="Sarala User Access">
      <s-section heading="Last 30 days">
        <s-grid gridTemplateColumns="repeat(4, 1fr)" gap="base">
          <Stat label="New customer accounts" value={stats.signups} />
          <Stat label="Waiting for approval" value={stats.pending} href="/app/customers?view=pending" />
          <Stat label="Approved" value={stats.approved} href="/app/customers?view=approved" />
          <Stat label="Look fake" value={stats.suspicious} href="/app/customers?view=suspicious" />
        </s-grid>
      </s-section>
      <s-section heading="Set up">
        <s-stack gap="base">
          {steps.map((s, i) => (
            <s-box key={s.title} padding="base" borderWidth="base" borderRadius="base">
              <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                <s-stack gap="small-200">
                  <s-text type="strong">{`${i + 1}. ${s.title}`} {s.done ? "✓" : ""}</s-text>
                  <s-text color="subdued">{s.text}</s-text>
                </s-stack>
                <s-button href={s.href} target={s.external ? "_blank" : undefined}>{s.action}</s-button>
              </s-stack>
            </s-box>
          ))}
        </s-stack>
      </s-section>
      <s-section slot="aside" heading="Help">
        <s-paragraph>
          New here? Read the <s-link href="https://laghavi.site/guides/" target="_blank">guide</s-link>, or write to{" "}
          <s-link href="mailto:info@laghavi.site">info@laghavi.site</s-link>.
        </s-paragraph>
      </s-section>
    </s-page>
  );
}

export const headers: HeadersFunction = (headersArgs) => boundary.headers(headersArgs);
