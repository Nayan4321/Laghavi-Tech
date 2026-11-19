import { createContext, createElement, useContext, useEffect, useState, type ReactNode } from "react";

/** Calls the app's PHP API. App Bridge adds the session token (Authorization header) to every fetch. */
export async function api<T>(path: string, body?: unknown): Promise<T> {
  const res = await fetch(path, {
    method: body === undefined ? "GET" : "POST",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let data: unknown = null;
  try {
    data = await res.json();
  } catch {
    // Not JSON.
  }
  const d = data as { error?: string } | null;
  if (!res.ok) throw new Error(d?.error || "Something went wrong. Please try again.");
  return data as T;
}

const DataContext = createContext<unknown>(null);

/** Data of the current page, loaded by <Loaded>. */
export function useData<T>(): T {
  return useContext(DataContext) as T;
}

export function Loaded({ path, children }: { path: string; children: ReactNode }) {
  const [state, setState] = useState<{ path: string; data?: unknown; error?: string }>({ path: "" });
  useEffect(() => {
    let live = true;
    api(path)
      .then((data) => live && setState({ path, data }))
      .catch((e: Error) => live && setState({ path, error: e.message }));
    return () => {
      live = false;
    };
  }, [path]);
  if (state.path !== path) {
    return createElement("s-page", null, createElement("s-section", null, createElement("s-spinner", { accessibilityLabel: "Loading" })));
  }
  if (state.error) {
    return createElement("s-page", null, createElement("s-banner", { tone: "critical" }, state.error));
  }
  return createElement(DataContext.Provider, { value: state.data }, children);
}

/** Saves to the API, with the same shape as React Router's fetcher (state, data, submit). */
export function useFetcher<T>(path: string) {
  const [state, setState] = useState<"idle" | "submitting">("idle");
  const [data, setData] = useState<T | undefined>(undefined);
  const submit = async (body: unknown, _options?: unknown) => {
    setState("submitting");
    try {
      setData(await api<T>(path, body));
    } catch (e) {
      const message = (e as Error).message;
      setData({ ok: false, error: message, message } as T);
    }
    setState("idle");
  };
  return { state, data, submit };
}
