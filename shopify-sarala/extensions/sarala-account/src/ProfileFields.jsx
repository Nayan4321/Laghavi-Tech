import "@shopify/ui-extensions/preact";
import { render } from "preact";
import { useEffect, useState } from "preact/hooks";
import { APP_URL, cag, loadAccount } from "./api.js";

export default async () => {
  render(<ProfileFields />, document.body);
};

const key = (f) => `f_${f.key}`;

// Saved metafield value -> form value, and back.
function fromSaved(f, raw) {
  if (raw == null) return f.type === "checkboxes" ? [] : f.type === "yes_no" ? false : "";
  if (f.type === "checkboxes") {
    try { return JSON.parse(raw); } catch (e) { return []; }
  }
  if (f.type === "yes_no") return raw === "true";
  return raw;
}
function toSaved(f, v) {
  if (f.type === "checkboxes") return JSON.stringify(v);
  if (f.type === "yes_no") return v ? "true" : "false";
  return String(v).trim();
}
const TYPES = {
  text: "single_line_text_field", textarea: "multi_line_text_field", number: "number_decimal", date: "date",
  dropdown: "single_line_text_field", radio: "single_line_text_field", checkboxes: "list.single_line_text_field", yes_no: "boolean",
};
function isEmpty(f, v) {
  if (f.type === "checkboxes") return v.length === 0;
  if (f.type === "yes_no") return false;
  return String(v).trim() === "";
}

function ProfileFields() {
  const t = shopify.i18n.translate;
  const [state, setState] = useState({ loading: true });
  const [values, setValues] = useState({});
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);

  useEffect(() => {
    (async () => {
      try {
        const { config, customer } = await loadAccount();
        if (!config || !config.active || !config.fields?.length) return setState({ loading: false, hidden: true });
        const data = await cag(
          `query SaralaAnswers($ids: [HasMetafieldsIdentifier!]!) { customer { metafields(identifiers: $ids) { key value } } }`,
          { ids: config.fields.map((f) => ({ namespace: "$app", key: key(f) })) },
        );
        const saved = {};
        for (const m of data.customer.metafields) if (m) saved[m.key] = m.value;
        const v = {};
        for (const f of config.fields) v[f.key] = fromSaved(f, saved[key(f)]);
        setValues(v);
        setState({ loading: false, config, customer });
      } catch (e) {
        setState({ loading: false, failed: true });
      }
    })();
  }, []);

  if (state.loading) return <s-section><s-skeleton-paragraph /></s-section>;
  if (state.hidden) return null;
  if (state.failed) return <s-section><s-banner tone="critical">{t("loadFailed")}</s-banner></s-section>;

  const { config, customer } = state;
  const set = (k, v) => setValues((x) => ({ ...x, [k]: v }));

  const save = async () => {
    const errs = {};
    for (const f of config.fields) {
      const v = values[f.key];
      if (f.required && isEmpty(f, v)) errs[f.key] = t("required");
      else if (f.type === "number" && !isEmpty(f, v) && !/^-?\d+(\.\d+)?$/.test(String(v).trim())) errs[f.key] = t("number");
    }
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSaving(true);
    setNotice(null);
    try {
      const metafields = [];
      const remove = [];
      for (const f of config.fields) {
        const v = values[f.key];
        if (isEmpty(f, v)) remove.push(key(f));
        else metafields.push({ ownerId: customer.id, namespace: "$app", key: key(f), type: TYPES[f.type], value: toSaved(f, v) });
      }
      if (metafields.length) {
        const data = await cag(
          `mutation SaralaSave($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) { userErrors { field message } } }`,
          { metafields },
        );
        if (data.metafieldsSet.userErrors.length) throw new Error(data.metafieldsSet.userErrors[0].message);
      }
      // Smart tags are added by the app, which checks the answers again on its side.
      const token = await shopify.sessionToken.get();
      await fetch(`${APP_URL}/api/account/saved`, { method: "POST", headers: { Authorization: `Bearer ${token}` } }).catch(() => {});
      setNotice({ tone: "success", text: t("saved") });
    } catch (e) {
      setNotice({ tone: "critical", text: t("saveFailed") });
    }
    setSaving(false);
  };

  return (
    <s-section heading={config.fieldsTitle || t("title")}>
      <s-stack gap="base">
        {config.fieldsIntro && <s-paragraph>{config.fieldsIntro}</s-paragraph>}
        {notice && <s-banner tone={notice.tone}>{notice.text}</s-banner>}
        {config.fields.map((f) => (
          <Field key={f.key} f={f} value={values[f.key]} error={errors[f.key]} onChange={(v) => set(f.key, v)} />
        ))}
        <s-stack direction="inline">
          <s-button variant="primary" loading={saving} onClick={save}>{t("save")}</s-button>
        </s-stack>
      </s-stack>
    </s-section>
  );
}

function Field({ f, value, error, onChange }) {
  const label = f.label;
  const common = { label, required: f.required, error, details: f.help || undefined };
  switch (f.type) {
    case "textarea":
      return <s-text-area {...common} value={value} onInput={(e) => onChange(e.currentTarget.value)} />;
    case "number":
      return <s-number-field {...common} value={value} onInput={(e) => onChange(e.currentTarget.value)} />;
    case "date":
      return <s-date-field {...common} value={value} onChange={(e) => onChange(e.currentTarget.value)} />;
    case "dropdown":
      return (
        <s-select {...common} value={value} onChange={(e) => onChange(e.currentTarget.value)}>
          <s-option value="">—</s-option>
          {f.options.map((o) => <s-option key={o} value={o}>{o}</s-option>)}
        </s-select>
      );
    case "radio":
    case "checkboxes":
      return (
        <s-choice-list
          {...common}
          multiple={f.type === "checkboxes"}
          values={f.type === "checkboxes" ? value : value ? [value] : []}
          onChange={(e) => {
            const v = e.currentTarget.values;
            onChange(f.type === "checkboxes" ? v : v[0] || "");
          }}
        >
          {f.options.map((o) => <s-choice key={o} value={o}>{o}</s-choice>)}
        </s-choice-list>
      );
    case "yes_no":
      return <s-checkbox label={label} details={f.help || undefined} checked={value} onChange={(e) => onChange(e.currentTarget.checked)} />;
    default:
      return <s-text-field {...common} value={value} onInput={(e) => onChange(e.currentTarget.value)} />;
  }
}
