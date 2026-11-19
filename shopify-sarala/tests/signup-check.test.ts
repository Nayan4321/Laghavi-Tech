import { describe, expect, it } from "vitest";
import { checkSignup } from "../web/lib/signup-check";

describe("checkSignup", () => {
  it("lets normal customers through", () => {
    expect(checkSignup({ email: "priya.sharma@gmail.com", firstName: "Priya", lastName: "Sharma" }).suspicious).toBe(false);
    expect(checkSignup({ email: "john1985@outlook.com", firstName: "John", lastName: "Smith" }).suspicious).toBe(false);
  });
  it("flags throwaway, random and spammy sign-ups", () => {
    expect(checkSignup({ email: "a@mailinator.com" }).reasons).toContain("Throwaway email service");
    expect(checkSignup({ email: "x@sub.yopmail.com" }).suspicious).toBe(true);
    expect(checkSignup({ email: "user84736251@gmail.com" }).suspicious).toBe(true);
    expect(checkSignup({ email: "xkqzvtrwplmn@gmail.com" }).suspicious).toBe(true);
    expect(checkSignup({ email: "a@b.com", firstName: "Win money www.spam.ru" }).suspicious).toBe(true);
    expect(checkSignup({ email: "a@b.com", firstName: "Robert", lastName: "robert" }).suspicious).toBe(true);
  });
});
