/**
 * Settings of Sarala User Access, and their validation. Pure functions only (no Shopify calls), so
 * everything here is unit-tested.
 *
 * Where each setting lives in Shopify:
 * - Fields and account page messages: a shop metafield ($app / account), read by the account blocks.
 * - Access rules: an app installation metafield (sarala / access), read by the theme app embed.
 * - Each customer's answers: one customer metafield per field ($app / f_<key>), visible in the admin.
 */

export const FIELD_TYPES = [
  "text",
  "textarea",
  "number",
  "date",
  "dropdown",
  "radio",
  "checkboxes",
  "yes_no",
] as const;
export type FieldType = (typeof FIELD_TYPES)[number];

/** Shopify metafield type used to store each kind of answer. */
export const METAFIELD_TYPES: Record<FieldType, string> = {
  text: "single_line_text_field",
  textarea: "multi_line_text_field",
  number: "number_decimal",
  date: "date",
  dropdown: "single_line_text_field",
  radio: "single_line_text_field",
  checkboxes: "list.single_line_text_field",
  yes_no: "boolean",
};

export interface TagRule {
  /** The answer (an option) that adds the tag. For yes/no fields: "yes". */
  answer: string;
  tag: string;
}

export interface FieldDef {
  key: string;
  label: string;
  type: FieldType;
  required: boolean;
  help: string;
  options: string[];
  tagRules: TagRule[];
}

export interface Announcement {
  /** Show to customers with this tag; "" = everyone. */
  tag: string;
  title: string;
  text: string;
}

export interface AccountConfig {
  version: 1;
  active: boolean;
  fields: FieldDef[];
  fieldsTitle: string;
  fieldsIntro: string;
  pendingMessage: string;
  announcements: Announcement[];
}

export interface RedirectRule {
  tag: string;
  url: string;
}

export interface AccessConfig {
  version: 1;
  active: boolean;
  approval: boolean;
  storeLock: boolean;
  lockedPages: string[];
  lockedCollections: string[];
  lockedProducts: string[];
  allowedTags: string[];
  hidePrices: boolean;
  lockMessage: string;
  lockButton: string;
  prompt: boolean;
  promptText: string;
  promptButton: string;
  redirects: RedirectRule[];
  priceSelectors: string;
  cartSelectors: string;
}

export const PENDING_TAG = "sarala-pending";
export const APPROVED_TAG = "sarala-approved";
export const SUSPICIOUS_TAG = "sarala-suspicious";

export const DEFAULT_PRICE_SELECTORS =
  ".price, .price__container, .product__price, [data-product-price], .card-information .price, product-price";
export const DEFAULT_CART_SELECTORS =
  'form[action*="/cart/add"], .product-form, product-form, .shopify-payment-button, [name="add"], .quick-add';

export function defaultAccountConfig(): AccountConfig {
  return {
    version: 1,
    active: false,
    fields: [],
    fieldsTitle: "About you",
    fieldsIntro: "",
    pendingMessage: "Thank you for signing up. Your account is waiting for approval; we will let you know as soon as it is ready.",
    announcements: [],
  };
}

export function defaultAccessConfig(): AccessConfig {
  return {
    version: 1,
    active: false,
    approval: false,
    storeLock: false,
    lockedPages: [],
    lockedCollections: [],
    lockedProducts: [],
    allowedTags: [],
    hidePrices: false,
    lockMessage: "Please sign in to see this page.",
    lockButton: "Sign in",
    prompt: false,
    promptText: "Sign in to see members' prices and offers.",
    promptButton: "Sign in",
    redirects: [],
    priceSelectors: DEFAULT_PRICE_SELECTORS,
    cartSelectors: DEFAULT_CART_SELECTORS,
  };
}

const str = (v: unknown, max: number): string =>
  typeof v === "string" ? v.replace(/[\u0000-\u001f\u007f]/g, " ").trim().slice(0, max) : "";

const multiline = (v: unknown, max: number): string =>
  typeof v === "string" ? v.replace(/\r\n?/g, "\n").replace(/[\u0000-\u0009\u000b-\u001f\u007f]/g, "").trim().slice(0, max) : "";

/** A Shopify tag: no commas, at most 40 characters. */
export function cleanTag(v: unknown): string {
  return str(v, 40).replace(/,/g, " ").replace(/\s+/g, " ").trim();
}

/** A handle (pages, products, collections): lower case letters, digits and dashes. */
export function cleanHandle(v: unknown): string {
  let h = str(v, 255).toLowerCase();
  // Accept full addresses: https://shop.com/products/blue-shirt -> blue-shirt
  const m = h.match(/\/(?:pages|products|collections)\/([^/?#]+)/);
  if (m) h = m[1];
  return h.replace(/[^\p{L}\p{N}-]+/gu, "-").replace(/^-+|-+$/g, "").slice(0, 255);
}

/** A field key: starts with a letter, letters/digits/underscores, at most 30 characters. */
export function cleanKey(v: unknown, fallback = "field"): string {
  let k = str(v, 60)
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 30);
  if (!/^[a-z]/.test(k)) k = (fallback + "_" + k).replace(/_+$/, "").slice(0, 30);
  return k;
}

/** Only links inside the shop (/pages/vip) or to https addresses. */
export function cleanUrl(v: unknown): string {
  const u = str(v, 500);
  if (/^\/(?!\/)[^\s]*$/.test(u)) return u;
  try {
    const parsed = new URL(u);
    return parsed.protocol === "https:" ? parsed.toString() : "";
  } catch {
    return "";
  }
}

/** Simple CSS selectors only, so a setting can never break out of the style rule. */
export function cleanSelectors(v: unknown, fallback: string): string {
  const s = str(v, 1000);
  if (s === "") return fallback;
  return /^[\w\s.#\-[\]="'*:,>()~+^$|]+$/.test(s) && !/[{}<>]|\/\*/.test(s.replace(/>/g, "")) ? s : fallback;
}

function list<T>(v: unknown, max: number, each: (x: unknown) => T | null): T[] {
  if (!Array.isArray(v)) return [];
  const out: T[] = [];
  for (const x of v.slice(0, max * 2)) {
    const c = each(x);
    if (c !== null) out.push(c);
    if (out.length >= max) break;
  }
  return out;
}

function lines(v: unknown, max: number, clean: (x: unknown) => string): string[] {
  const raw = Array.isArray(v) ? v : typeof v === "string" ? v.split(/[\n,]+/) : [];
  return [...new Set(raw.map(clean).filter((x) => x !== ""))].slice(0, max);
}

export function cleanField(v: unknown, used: Set<string>): FieldDef | null {
  if (!v || typeof v !== "object") return null;
  const f = v as Record<string, unknown>;
  const label = str(f.label, 80);
  if (label === "") return null;
  const type = (FIELD_TYPES as readonly string[]).includes(f.type as string) ? (f.type as FieldType) : "text";
  let key = cleanKey(f.key || label);
  // Keys must be unique: blue, blue_2, blue_3…
  let n = 2;
  const base = key.slice(0, 27);
  while (used.has(key)) key = `${base}_${n++}`;
  used.add(key);
  const needsOptions = type === "dropdown" || type === "radio" || type === "checkboxes";
  const options = needsOptions ? lines(f.options, 50, (x) => str(x, 100)) : [];
  const allowedAnswers = type === "yes_no" ? ["yes"] : options;
  const tagRules = list(f.tagRules, 20, (r) => {
    if (!r || typeof r !== "object") return null;
    const rr = r as Record<string, unknown>;
    const answer = str(rr.answer, 100);
    const tag = cleanTag(rr.tag);
    return tag !== "" && allowedAnswers.includes(answer) ? { answer, tag } : null;
  });
  if (needsOptions && options.length === 0) return null;
  return { key, label, type, required: Boolean(f.required), help: str(f.help, 200), options, tagRules };
}

export function cleanAccountConfig(v: unknown): AccountConfig {
  const d = defaultAccountConfig();
  if (!v || typeof v !== "object") return d;
  const c = v as Record<string, unknown>;
  const used = new Set<string>();
  return {
    version: 1,
    active: Boolean(c.active),
    fields: list(c.fields, 25, (f) => cleanField(f, used)),
    fieldsTitle: str(c.fieldsTitle, 80) || d.fieldsTitle,
    fieldsIntro: multiline(c.fieldsIntro, 300),
    pendingMessage: multiline(c.pendingMessage, 400) || d.pendingMessage,
    announcements: list(c.announcements, 10, (a) => {
      if (!a || typeof a !== "object") return null;
      const aa = a as Record<string, unknown>;
      const text = multiline(aa.text, 600);
      const title = str(aa.title, 80);
      return text === "" && title === "" ? null : { tag: cleanTag(aa.tag), title, text };
    }),
  };
}

export function cleanAccessConfig(v: unknown): AccessConfig {
  const d = defaultAccessConfig();
  if (!v || typeof v !== "object") return d;
  const c = v as Record<string, unknown>;
  return {
    version: 1,
    active: Boolean(c.active),
    approval: Boolean(c.approval),
    storeLock: Boolean(c.storeLock),
    lockedPages: lines(c.lockedPages, 100, cleanHandle),
    lockedCollections: lines(c.lockedCollections, 100, cleanHandle),
    lockedProducts: lines(c.lockedProducts, 200, cleanHandle),
    allowedTags: lines(c.allowedTags, 20, cleanTag),
    hidePrices: Boolean(c.hidePrices),
    lockMessage: multiline(c.lockMessage, 300) || d.lockMessage,
    lockButton: str(c.lockButton, 40) || d.lockButton,
    prompt: Boolean(c.prompt),
    promptText: str(c.promptText, 200) || d.promptText,
    promptButton: str(c.promptButton, 40) || d.promptButton,
    redirects: list(c.redirects, 10, (r) => {
      if (!r || typeof r !== "object") return null;
      const rr = r as Record<string, unknown>;
      const url = cleanUrl(rr.url);
      return url === "" ? null : { tag: cleanTag(rr.tag), url };
    }),
    priceSelectors: cleanSelectors(c.priceSelectors, d.priceSelectors),
    cartSelectors: cleanSelectors(c.cartSelectors, d.cartSelectors),
  };
}

/** Metafield key of a field's answers on the customer. */
export const answerKey = (field: Pick<FieldDef, "key">) => `f_${field.key}`;

/**
 * An answer as sent by the account block, checked against the field. Returns the metafield value
 * (a string, as Shopify expects) or null when the answer is not valid.
 */
export function validateAnswer(field: FieldDef, raw: unknown): { value: string | null; error: string } {
  const empty = raw === undefined || raw === null || raw === "" || (Array.isArray(raw) && raw.length === 0);
  if (empty) return { value: null, error: field.required ? "required" : "" };
  switch (field.type) {
    case "text":
      return { value: str(raw, 255) || null, error: "" };
    case "textarea":
      return { value: multiline(raw, 2000) || null, error: "" };
    case "number": {
      const n = typeof raw === "number" ? raw : Number(String(raw).replace(",", "."));
      return Number.isFinite(n) ? { value: String(n), error: "" } : { value: null, error: "number" };
    }
    case "date": {
      const s = String(raw);
      return /^\d{4}-\d{2}-\d{2}$/.test(s) && !Number.isNaN(Date.parse(s)) ? { value: s, error: "" } : { value: null, error: "date" };
    }
    case "dropdown":
    case "radio":
      return field.options.includes(String(raw)) ? { value: String(raw), error: "" } : { value: null, error: "option" };
    case "checkboxes": {
      const picked = (Array.isArray(raw) ? raw : [raw]).map(String).filter((x) => field.options.includes(x));
      return picked.length ? { value: JSON.stringify([...new Set(picked)]), error: "" } : { value: null, error: "option" };
    }
    case "yes_no":
      return { value: raw === true || raw === "true" || raw === "yes" ? "true" : "false", error: "" };
  }
}

/**
 * Tags a customer should have from their answers, and the tags this app manages (so tags from
 * answers the customer changed are removed, and tags the merchant added by hand are kept).
 */
export function tagsFromAnswers(
  fields: FieldDef[],
  answers: Record<string, string | null | undefined>,
): { add: string[]; managed: string[] } {
  const add = new Set<string>();
  const managed = new Set<string>();
  for (const field of fields) {
    for (const rule of field.tagRules) {
      managed.add(rule.tag);
      const v = answers[answerKey(field)];
      if (v === null || v === undefined) continue;
      let hit = false;
      if (field.type === "checkboxes") {
        try {
          hit = (JSON.parse(v) as string[]).includes(rule.answer);
        } catch {
          hit = false;
        }
      } else if (field.type === "yes_no") {
        hit = v === "true" && rule.answer === "yes";
      } else {
        hit = v === rule.answer;
      }
      if (hit) add.add(rule.tag);
    }
  }
  return { add: [...add], managed: [...managed] };
}
