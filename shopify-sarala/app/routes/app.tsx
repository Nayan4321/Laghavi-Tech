import type { HeadersFunction, LoaderFunctionArgs } from "react-router";
import { Outlet, useLoaderData, useRouteError } from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { AppProvider } from "@shopify/shopify-app-react-router/react";
import { authenticate } from "../shopify.server";

/**
 * Every app page needs an active plan. During the 15-day free trial the plan is active; without one
 * the merchant is sent to Shopify's plan page (Shopify App Pricing, set in the Partner Dashboard).
 */
export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { billing, redirect, session } = await authenticate.admin(request);
  const { hasActivePayment } = await billing.check();
  if (!hasActivePayment) {
    const store = session.shop.replace(/\.myshopify\.com$/, "");
    const app = process.env.SHOPIFY_APP_HANDLE || "sarala-user-access";
    throw redirect(`https://admin.shopify.com/store/${store}/charges/${app}/pricing_plans`, { target: "_top" });
  }
  return { apiKey: process.env.SHOPIFY_API_KEY || "" };
};

export default function AppLayout() {
  const { apiKey } = useLoaderData<typeof loader>();
  return (
    <AppProvider apiKey={apiKey}>
      <s-app-nav>
        <s-link href="/app">Home</s-link>
        <s-link href="/app/fields">Sign-up &amp; profile fields</s-link>
        <s-link href="/app/access">Access &amp; approval</s-link>
        <s-link href="/app/account">Account page</s-link>
        <s-link href="/app/customers">Customers to review</s-link>
      </s-app-nav>
      <Outlet />
    </AppProvider>
  );
}

export function ErrorBoundary() {
  return boundary.error(useRouteError());
}

export const headers: HeadersFunction = (headersArgs) => boundary.headers(headersArgs);
