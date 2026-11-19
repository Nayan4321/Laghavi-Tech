import { StrictMode, useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, Route, Routes, useLocation, useNavigate } from "react-router";
import { Loaded, api } from "./api";
import Home from "./pages/Home";
import Fields from "./pages/Fields";
import Access from "./pages/Access";
import Account from "./pages/Account";
import Customers from "./pages/Customers";

/** Links inside the app (nav menu, buttons, links) change the page without reloading the frame. */
function useAppLinks() {
  const navigate = useNavigate();
  useEffect(() => {
    const onNavigate = (e: Event) => {
      const href = (e.target as HTMLElement | null)?.getAttribute?.("href");
      if (href) navigate(href);
    };
    const onClick = (e: MouseEvent) => {
      const el = (e.target as HTMLElement | null)?.closest?.("[href]");
      const href = el?.getAttribute("href");
      if (!href || !href.startsWith("/app") || el?.getAttribute("target")) return;
      e.preventDefault();
      navigate(href);
    };
    document.addEventListener("shopify:navigate", onNavigate);
    document.addEventListener("click", onClick);
    return () => {
      document.removeEventListener("shopify:navigate", onNavigate);
      document.removeEventListener("click", onClick);
    };
  }, [navigate]);
}

function App() {
  useAppLinks();
  const location = useLocation();
  const [ready, setReady] = useState<"wait" | "ok" | string>("wait");

  // Every page needs an active plan (the 15-day trial counts); otherwise Shopify's plan page opens.
  useEffect(() => {
    api<{ subscribed: boolean; pricingUrl: string }>("/api/boot")
      .then((b) => {
        if (b.subscribed) setReady("ok");
        else window.open(b.pricingUrl, "_top");
      })
      .catch((e: Error) => setReady(e.message));
  }, []);

  if (ready === "wait") return <s-page><s-section><s-spinner accessibilityLabel="Loading" /></s-section></s-page>;
  if (ready !== "ok") return <s-page><s-banner tone="critical">{ready}</s-banner></s-page>;

  const view = new URLSearchParams(location.search).get("view") || "pending";
  return (
    <>
      <s-app-nav>
        <s-link href="/app">Home</s-link>
        <s-link href="/app/fields">Sign-up &amp; profile fields</s-link>
        <s-link href="/app/access">Access &amp; approval</s-link>
        <s-link href="/app/account">Account page</s-link>
        <s-link href="/app/customers">Customers to review</s-link>
      </s-app-nav>
      <Routes>
        <Route path="/app/fields" element={<Loaded path="/api/fields"><Fields /></Loaded>} />
        <Route path="/app/access" element={<Loaded path="/api/access"><Access /></Loaded>} />
        <Route path="/app/account" element={<Loaded path="/api/account"><Account /></Loaded>} />
        <Route path="/app/customers" element={<Loaded key={view} path={`/api/customers?view=${encodeURIComponent(view)}`}><Customers /></Loaded>} />
        <Route path="*" element={<Loaded path="/api/home"><Home /></Loaded>} />
      </Routes>
    </>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
