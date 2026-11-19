import { useEffect, useState } from "react";
import { type AccountConfig, type FieldDef, type FieldType } from "../lib/config";
import { useData, useFetcher } from "../api";

type FieldsData = { account: AccountConfig };
type FieldsResult = { ok: boolean; error: string; account: AccountConfig };

const TYPE_LABELS: Record<FieldType, string> = {
  text: "Short text",
  textarea: "Long text",
  number: "Number",
  date: "Date",
  dropdown: "Dropdown",
  radio: "Choose one (radio buttons)",
  checkboxes: "Choose several (checkboxes)",
  yes_no: "Yes or no",
};
const WITH_OPTIONS: FieldType[] = ["dropdown", "radio", "checkboxes"];

type Draft = FieldDef & { _id: string; optionsText: string };

const toDraft = (f: FieldDef, i: number): Draft => ({ ...f, _id: `${f.key}-${i}`, optionsText: f.options.join("\n") });

export default function Fields() {
  const { account } = useData<FieldsData>();
  const fetcher = useFetcher<FieldsResult>("/api/fields");
  const [fields, setFields] = useState<Draft[]>(() => account.fields.map(toDraft));
  const [title, setTitle] = useState(account.fieldsTitle);
  const [intro, setIntro] = useState(account.fieldsIntro);
  const saving = fetcher.state !== "idle";

  useEffect(() => {
    if (fetcher.state === "idle" && fetcher.data) {
      if (fetcher.data.ok) {
        setFields(fetcher.data.account.fields.map(toDraft));
        shopify.toast.show("Fields saved");
      } else {
        shopify.toast.show(fetcher.data.error, { isError: true });
      }
    }
  }, [fetcher.state, fetcher.data]);

  const update = (id: string, patch: Partial<Draft>) =>
    setFields((all) => all.map((f) => (f._id === id ? { ...f, ...patch } : f)));
  const move = (index: number, by: number) =>
    setFields((all) => {
      const next = [...all];
      const [f] = next.splice(index, 1);
      next.splice(Math.max(0, Math.min(next.length, index + by)), 0, f);
      return next;
    });
  const add = () =>
    setFields((all) => [
      ...all,
      { _id: `new-${Date.now()}`, key: "", label: "", type: "text", required: false, help: "", options: [], optionsText: "", tagRules: [] },
    ]);

  const save = () =>
    fetcher.submit(
      {
        fieldsTitle: title,
        fieldsIntro: intro,
        fields: fields.map((f) => ({
          key: f.key,
          label: f.label,
          type: f.type,
          required: f.required,
          help: f.help,
          options: f.optionsText.split("\n"),
          tagRules: f.tagRules,
        })),
      },
      { method: "post", encType: "application/json" },
    );

  return (
    <s-page heading="Sign-up & profile fields">
      <s-button slot="primary-action" variant="primary" onClick={save} loading={saving || undefined}>
        Save
      </s-button>
      <s-section heading="On the customer's profile page">
        <s-stack gap="base">
          <s-paragraph>
            Customers fill these in on the Profile page of their account, and can change their answers later. Each answer is saved on the customer in Shopify: you see it on the customer's page in your admin, and can use it in customer segments and Shopify Email.
          </s-paragraph>
          <s-text-field label="Title of the block" value={title} onInput={(e) => setTitle(e.currentTarget.value)} />
          <s-text-area label="Text above the fields (optional)" value={intro} rows={2} onInput={(e) => setIntro(e.currentTarget.value)} />
        </s-stack>
      </s-section>

      {fields.length === 0 && (
        <s-section>
          <s-paragraph>No fields yet. Add your first field: for example “Company name”, “Birthday” or “What are you interested in?”.</s-paragraph>
        </s-section>
      )}

      {fields.map((f, i) => {
        const answers = f.type === "yes_no" ? ["yes"] : f.optionsText.split("\n").map((o) => o.trim()).filter(Boolean);
        return (
          <s-section key={f._id} heading={f.label || "New field"}>
            <s-stack gap="base">
              <s-grid gridTemplateColumns="2fr 1fr" gap="base">
                <s-text-field label="Question" required value={f.label} onInput={(e) => update(f._id, { label: e.currentTarget.value })} />
                <s-select label="Type of answer" value={f.type} onChange={(e) => update(f._id, { type: e.currentTarget.value as FieldType })}>
                  {Object.entries(TYPE_LABELS).map(([value, label]) => (
                    <s-option key={value} value={value}>{label}</s-option>
                  ))}
                </s-select>
              </s-grid>
              {WITH_OPTIONS.includes(f.type) && (
                <s-text-area
                  label="Choices, one per line"
                  rows={4}
                  value={f.optionsText}
                  details="Customers choose from these."
                  onInput={(e) => update(f._id, { optionsText: e.currentTarget.value })}
                />
              )}
              <s-text-field label="Help text (optional)" value={f.help} onInput={(e) => update(f._id, { help: e.currentTarget.value })} />
              <s-checkbox label="Required" checked={f.required || undefined} onChange={(e) => update(f._id, { required: e.currentTarget.checked })} />

              {(WITH_OPTIONS.includes(f.type) || f.type === "yes_no") && (
                <s-box padding="base" background="subdued" borderRadius="base">
                  <s-stack gap="small-300">
                    <s-text type="strong">Smart tags</s-text>
                    <s-text color="subdued">Tag customers by their answer, for example “Wholesale” or “Interest: Shoes”. Tags appear on the customer in Shopify and work with segments, discounts and Sarala's access rules.</s-text>
                    {f.tagRules.map((r, ri) => (
                      <s-grid key={ri} gridTemplateColumns="1fr 1fr auto" gap="small-300" alignItems="end">
                        <s-select
                          label="When the answer is"
                          value={r.answer}
                          onChange={(e) => {
                            const tagRules = [...f.tagRules];
                            tagRules[ri] = { ...r, answer: e.currentTarget.value };
                            update(f._id, { tagRules });
                          }}
                        >
                          <s-option value="">Choose…</s-option>
                          {answers.map((a) => (
                            <s-option key={a} value={a}>{a === "yes" && f.type === "yes_no" ? "Yes" : a}</s-option>
                          ))}
                        </s-select>
                        <s-text-field
                          label="Add the tag"
                          value={r.tag}
                          onInput={(e) => {
                            const tagRules = [...f.tagRules];
                            tagRules[ri] = { ...r, tag: e.currentTarget.value };
                            update(f._id, { tagRules });
                          }}
                        />
                        <s-button variant="tertiary" tone="critical" onClick={() => update(f._id, { tagRules: f.tagRules.filter((_, x) => x !== ri) })}>
                          Remove
                        </s-button>
                      </s-grid>
                    ))}
                    <s-stack direction="inline">
                      <s-button variant="secondary" onClick={() => update(f._id, { tagRules: [...f.tagRules, { answer: "", tag: "" }] })}>Add a smart tag</s-button>
                    </s-stack>
                  </s-stack>
                </s-box>
              )}

              <s-stack direction="inline" gap="small-300" justifyContent="space-between">
                <s-stack direction="inline" gap="small-300">
                  <s-button variant="tertiary" disabled={i === 0 || undefined} onClick={() => move(i, -1)}>Move up</s-button>
                  <s-button variant="tertiary" disabled={i === fields.length - 1 || undefined} onClick={() => move(i, 1)}>Move down</s-button>
                </s-stack>
                <s-button variant="tertiary" tone="critical" onClick={() => setFields((all) => all.filter((x) => x._id !== f._id))}>
                  Delete field
                </s-button>
              </s-stack>
              {f.key && <s-text color="subdued">{`Saved on customers as metafield app–${f.key}. Deleting the field keeps the answers already given.`}</s-text>}
            </s-stack>
          </s-section>
        );
      })}

      <s-section>
        <s-stack direction="inline" gap="base">
          <s-button onClick={add}>Add a field</s-button>
          <s-button variant="primary" onClick={save} loading={saving || undefined}>Save</s-button>
        </s-stack>
      </s-section>
    </s-page>
  );
}

