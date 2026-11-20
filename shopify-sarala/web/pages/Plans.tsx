import { useState } from "react";
import { api } from "../api";

const FEATURES = [
  "Custom sign-up and profile fields with smart tags",
  "Approve new customer accounts",
  "Members-only store, pages, collections and products",
  "Hide prices and add to cart from guests",
  "Sign-in prompts and redirects after sign-in",
  "Fake sign-up detection",
];

/** Shown until the store has a plan. Shopify asks the merchant to approve the charge. */
export default function Plans({ trialDays }: { trialDays: number }) {
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const choose = async (plan: "monthly" | "yearly") => {
    setBusy(plan);
    setError("");
    try {
      const r = await api<{ ok: boolean; error?: string; confirmationUrl?: string }>("/api/subscribe", { plan });
      if (r.ok && r.confirmationUrl) {
        window.open(r.confirmationUrl, "_top");
        return;
      }
      setError(r.error || "Could not start the plan. Please try again.");
    } catch (e) {
      setError((e as Error).message);
    }
    setBusy("");
  };
  return (
    <s-page heading="Choose your plan">
      {error && <s-banner tone="critical">{error}</s-banner>}
      <s-section heading={`Every feature included. ${trialDays}-day free trial.`}>
        <s-stack gap="base">
          <s-unordered-list>
            {FEATURES.map((f) => (
              <s-list-item key={f}>{f}</s-list-item>
            ))}
          </s-unordered-list>
          <s-grid gridTemplateColumns="1fr 1fr" gap="base">
            <s-box padding="base" borderWidth="base" borderRadius="base">
              <s-stack gap="small-300">
                <s-heading>Monthly</s-heading>
                <s-text>9.99 USD every 30 days</s-text>
                <s-button variant="primary" onClick={() => choose("monthly")} loading={busy === "monthly" || undefined}>Start free trial</s-button>
              </s-stack>
            </s-box>
            <s-box padding="base" borderWidth="base" borderRadius="base">
              <s-stack gap="small-300">
                <s-heading>Yearly</s-heading>
                <s-text>99 USD per year (2 months free)</s-text>
                <s-button variant="primary" onClick={() => choose("yearly")} loading={busy === "yearly" || undefined}>Start free trial</s-button>
              </s-stack>
            </s-box>
          </s-grid>
          <s-text color="subdued">{`You are not charged during the ${trialDays}-day trial. Billing is handled by Shopify and appears on your Shopify invoice. Cancel any time by uninstalling the app.`}</s-text>
        </s-stack>
      </s-section>
    </s-page>
  );
}
