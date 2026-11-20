/** @jsxImportSource preact */
import "@shopify/ui-extensions/preact";
import { render } from "preact";
import { useEffect, useState } from "preact/hooks";
import { PENDING_TAG, hasTag, loadAccount } from "./api.js";

export default async () => {
  render(<Messages />, document.body);
};

function Messages() {
  const [items, setItems] = useState([]);
  useEffect(() => {
    loadAccount()
      .then(({ config, customer }) => {
        if (!config || !config.active) return;
        const tags = customer.tags || [];
        const out = [];
        if (hasTag(tags, PENDING_TAG) && config.pendingMessage) {
          out.push({ tone: "warning", title: "", text: config.pendingMessage });
        }
        for (const a of config.announcements || []) {
          if (!a.tag || hasTag(tags, a.tag)) out.push({ tone: "info", title: a.title, text: a.text });
        }
        setItems(out);
      })
      .catch(() => {});
  }, []);
  if (!items.length) return null;
  return (
    <s-stack gap="base">
      {items.map((m, i) => (
        <s-banner key={i} tone={m.tone} heading={m.title || undefined}>{m.text}</s-banner>
      ))}
    </s-stack>
  );
}
