export interface User {
  id: number;
  name: string;
  email: string;
  role: string;
}
export interface Session {
  user: User | null;
  csrf: { header: string; token: string };
}
// Domain records retain server-provided decimal strings to avoid client-side accounting.
export type Row = Record<string, any>;
export interface Page {
  data: Row[];
  total?: number;
  page?: number;
  per_page?: number;
}
let csrf: Session["csrf"] | undefined;
export async function api<T = Row>(path: string, body?: unknown): Promise<T> {
  if (body !== undefined && !csrf) await api<Session>("/session");
  const response = await fetch(`/api/v1${path}`, {
    method: body === undefined ? "GET" : "POST",
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      ...(body === undefined
        ? {}
        : { "Content-Type": "application/json", [csrf!.header]: csrf!.token }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = await response
    .json()
    .catch(() => ({
      message: "Server unavailable. Check that the API is running.",
    }));
  if (!response.ok)
    throw new Error(
      data.message ||
        data.messages?.error ||
        `Request failed (${response.status}). Refresh and try again.`,
    );
  if (data.csrf) csrf = data.csrf;
  return data as T;
}
