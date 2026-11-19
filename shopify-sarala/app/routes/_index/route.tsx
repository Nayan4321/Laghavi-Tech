import type { LoaderFunctionArgs } from "react-router";
import { Form, redirect, useLoaderData } from "react-router";
import { login } from "../../shopify.server";

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const url = new URL(request.url);
  if (url.searchParams.get("shop")) {
    throw redirect(`/app?${url.searchParams.toString()}`);
  }
  return { showForm: Boolean(login) };
};

const box: React.CSSProperties = { maxWidth: 560, margin: "64px auto", padding: "0 20px", fontFamily: "system-ui, sans-serif", lineHeight: 1.6, color: "#1f2937" };

export default function Index() {
  const { showForm } = useLoaderData<typeof loader>();
  return (
    <main style={box}>
      <h1 style={{ fontSize: 30, margin: 0 }}>Sarala User Access</h1>
      <p>Sign-up and profile fields, account approval, members-only pages and prices, smart customer tags and sign-in prompts for Shopify stores.</p>
      {showForm && (
        <Form method="post" action="/auth/login" style={{ display: "flex", gap: 8, marginTop: 24 }}>
          <input type="text" name="shop" placeholder="your-store.myshopify.com" style={{ flex: 1, padding: 10, border: "1px solid #ccc", borderRadius: 8 }} />
          <button type="submit" style={{ padding: "10px 18px", borderRadius: 8, border: 0, background: "#111827", color: "#fff" }}>Open</button>
        </Form>
      )}
      <p style={{ fontSize: 14, color: "#6b7280", marginTop: 32 }}>
        By Laghavi Digital · <a href="https://laghavi.site/sarala-user-access-privacy/">Privacy policy</a> · <a href="mailto:info@laghavi.site">info@laghavi.site</a>
      </p>
    </main>
  );
}
