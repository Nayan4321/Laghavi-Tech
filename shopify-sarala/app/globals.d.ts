import type { ReactNode } from "react";

// App Bridge declares <s-app-nav> for the global JSX namespace; React 19 uses React.JSX.
declare module "react" {
  namespace JSX {
    interface IntrinsicElements {
      "s-app-nav": { children?: ReactNode };
    }
  }
}
