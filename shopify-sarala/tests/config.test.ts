import { describe, expect, it } from "vitest";
import {
  DEFAULT_PRICE_SELECTORS,
  cleanAccessConfig,
  cleanAccountConfig,
  cleanHandle,
  cleanSelectors,
  cleanUrl,
  tagsFromAnswers,
  validateAnswer,
} from "../app/lib/config";

describe("cleaners", () => {
  it("accepts handles and full addresses", () => {
    expect(cleanHandle("Wholesale")).toBe("wholesale");
    expect(cleanHandle("https://shop.com/products/blue-shirt?variant=1")).toBe("blue-shirt");
    expect(cleanHandle("/pages/vip-area")).toBe("vip-area");
  });
  it("only allows site paths and https addresses", () => {
    expect(cleanUrl("/collections/wholesale")).toBe("/collections/wholesale");
    expect(cleanUrl("//evil.com")).toBe("");
    expect(cleanUrl("javascript:alert(1)")).toBe("");
    expect(cleanUrl("http://a.com")).toBe("");
    expect(cleanUrl("https://a.com/x")).toBe("https://a.com/x");
  });
  it("rejects selectors that could break out of the style tag", () => {
    expect(cleanSelectors(".price > span", "x")).toBe(".price > span");
    expect(cleanSelectors("</style><script>", "x")).toBe("x");
    expect(cleanSelectors(".a{color:red}", "x")).toBe("x");
    expect(cleanSelectors("", DEFAULT_PRICE_SELECTORS)).toBe(DEFAULT_PRICE_SELECTORS);
  });
  it("gives every field a unique key and drops bad ones", () => {
    const c = cleanAccountConfig({
      fields: [
        { label: "Company", type: "text" },
        { label: "Company", type: "text" },
        { label: "", type: "text" },
        { label: "Size", type: "dropdown", options: ["S", "M", "", "M"] },
        { label: "Bad", type: "script" },
      ],
    });
    const keys = c.fields.map((f) => f.key);
    expect(new Set(keys).size).toBe(keys.length);
    const size = c.fields.find((f) => f.label === "Size");
    expect(size?.options).toEqual(["S", "M"]);
    expect(c.fields.some((f) => f.label === "")).toBe(false);
  });
  it("fills defaults for an empty access config", () => {
    const a = cleanAccessConfig(null);
    expect(a.approval).toBe(false);
    expect(a.lockedPages).toEqual([]);
    expect(a.lockMessage.length).toBeGreaterThan(0);
  });
});

describe("answers and smart tags", () => {
  const color = { key: "color", label: "Color", type: "checkboxes" as const, required: true, help: "", options: ["Red", "Blue"], tagRules: [{ answer: "Red", tag: "Likes red" }] };
  const trade = { key: "trade", label: "Trade?", type: "yes_no" as const, required: false, help: "", options: [], tagRules: [{ answer: "yes", tag: "Wholesale" }] };

  it("validates answers", () => {
    expect(validateAnswer(color, []).error).toBe("required");
    expect(validateAnswer(color, ["Red", "Green"]).value).toBe('["Red"]');
    expect(validateAnswer({ ...color, type: "number", options: [] }, "12,5").value).toBe("12.5");
    expect(validateAnswer({ ...color, type: "date", options: [] }, "2026-02-30x").error).toBe("date");
  });
  it("adds tags that match and reports the managed ones", () => {
    const r = tagsFromAnswers([color, trade], { f_color: '["Red"]', f_trade: "false" });
    expect(r.add).toEqual(["Likes red"]);
    expect(r.managed.sort()).toEqual(["Likes red", "Wholesale"]);
    expect(tagsFromAnswers([trade], { f_trade: "true" }).add).toEqual(["Wholesale"]);
  });
});
