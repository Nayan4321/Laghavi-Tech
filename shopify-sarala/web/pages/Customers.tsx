import { useEffect } from "react";
import { useSearchParams } from "react-router";
import { APPROVED_TAG, PENDING_TAG, SUSPICIOUS_TAG } from "../lib/config";
import { useData, useFetcher } from "../api";

type CustomersData = { view: View; store: string; customers: { id: string; name: string; email: string; createdAt: string; tags: string[] }[] };
type CustomersResult = { ok: boolean; message: string };

const VIEWS = {
  pending: { tag: PENDING_TAG, title: "Waiting for approval", empty: "Nobody is waiting for approval." },
  suspicious: { tag: SUSPICIOUS_TAG, title: "Look fake", empty: "No suspicious sign-ups." },
  approved: { tag: APPROVED_TAG, title: "Approved", empty: "No approved customers yet." },
} as const;
type View = keyof typeof VIEWS;

function Row({ c, view, store }: { c: { id: string; name: string; email: string; createdAt: string }; view: View; store: string }) {
  const fetcher = useFetcher<CustomersResult>("/api/customers");
  useEffect(() => {
    if (fetcher.state === "idle" && fetcher.data) shopify.toast.show(fetcher.data.message, { isError: !fetcher.data.ok });
  }, [fetcher.state, fetcher.data]);
  // Done: the customer has left this list.
  if (fetcher.state === "idle" && fetcher.data?.ok) return null;
  const act = (todo: string) => fetcher.submit({ id: c.id, todo }, { method: "post" });
  const busy = fetcher.state !== "idle" || undefined;
  const numericId = c.id.split("/").pop();
  return (
    <s-table-row>
      <s-table-cell>
        <s-link href={`https://admin.shopify.com/store/${store}/customers/${numericId}`} target="_blank">{c.name || "(no name)"}</s-link>
      </s-table-cell>
      <s-table-cell>{c.email}</s-table-cell>
      <s-table-cell>{new Date(c.createdAt).toLocaleDateString()}</s-table-cell>
      <s-table-cell>
        <s-stack direction="inline" gap="small-300">
          {view === "pending" && (
            <>
              <s-button variant="primary" onClick={() => act("approve")} loading={busy}>Approve</s-button>
              <s-button tone="critical" onClick={() => act("decline")} loading={busy}>Decline</s-button>
            </>
          )}
          {view === "suspicious" && (
            <>
              <s-button onClick={() => act("keep")} loading={busy}>It's real</s-button>
              <s-button tone="critical" onClick={() => act("delete")} loading={busy}>Delete</s-button>
            </>
          )}
        </s-stack>
      </s-table-cell>
    </s-table-row>
  );
}

export default function Customers() {
  const { view, customers, store } = useData<CustomersData>();
  const [, setParams] = useSearchParams();
  return (
    <s-page heading="Customers to review">
      <s-section>
        <s-stack direction="inline" gap="small-300">
          {(Object.keys(VIEWS) as View[]).map((v) => (
            <s-button key={v} variant={v === view ? "primary" : "secondary"} onClick={() => setParams({ view: v })}>
              {VIEWS[v].title}
            </s-button>
          ))}
        </s-stack>
      </s-section>
      <s-section heading={VIEWS[view].title}>
        {view === "suspicious" && (
          <s-paragraph>Accounts made with throwaway email services, random-looking addresses or links in the name. Nothing is deleted unless you choose so.</s-paragraph>
        )}
        {customers.length === 0 ? (
          <s-paragraph>{VIEWS[view].empty}</s-paragraph>
        ) : (
          <s-table>
            <s-table-header-row>
              <s-table-header>Customer</s-table-header>
              <s-table-header>Email</s-table-header>
              <s-table-header>Signed up</s-table-header>
              <s-table-header>Actions</s-table-header>
            </s-table-header-row>
            <s-table-body>
              {customers.map((c) => (
                <Row key={c.id} c={c} view={view} store={store} />
              ))}
            </s-table-body>
          </s-table>
        )}
      </s-section>
    </s-page>
  );
}

