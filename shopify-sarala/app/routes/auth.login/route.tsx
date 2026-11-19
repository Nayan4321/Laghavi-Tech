import type { ActionFunctionArgs, LoaderFunctionArgs } from "react-router";
import { Form, useActionData, useLoaderData } from "react-router";
import { login } from "../../shopify.server";

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const errors = await login(request);
  return { errors };
};

export const action = async ({ request }: ActionFunctionArgs) => {
  const errors = await login(request);
  return { errors };
};

export default function Auth() {
  const loaderData = useLoaderData<typeof loader>();
  const actionData = useActionData<typeof action>();
  const errors = (actionData || loaderData).errors as { shop?: string } | undefined;
  return (
    <main style={{ maxWidth: 480, margin: "64px auto", fontFamily: "system-ui, sans-serif" }}>
      <h1>Log in</h1>
      <Form method="post">
        <label>
          Shop domain
          <input type="text" name="shop" placeholder="your-store.myshopify.com" style={{ display: "block", width: "100%", padding: 10, marginTop: 6 }} />
        </label>
        {errors?.shop && <p style={{ color: "#b91c1c" }}>{errors.shop}</p>}
        <button type="submit" style={{ marginTop: 12, padding: "10px 18px" }}>Log in</button>
      </Form>
    </main>
  );
}
