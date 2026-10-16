export const API_URL = "";

export function backendUrl(path = "") {
    if (/^https?:\/\//i.test(path)) {
        return path;
    }

    return path.startsWith("/") ? path : `/${path}`;
}
