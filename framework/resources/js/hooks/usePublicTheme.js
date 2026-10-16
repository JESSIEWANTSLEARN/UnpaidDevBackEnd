import { useEffect, useState } from "react";

const PUBLIC_THEME_KEY = "wbo-public-theme";

function readPublicTheme() {
  if (typeof window === "undefined") {
    return "light";
  }

  try {
    const savedTheme = window.localStorage.getItem(
      PUBLIC_THEME_KEY
    );

    if (savedTheme === "light" || savedTheme === "dark") {
      return savedTheme;
    }
  } catch {
    // Keep the public pages usable when browser storage is unavailable.
  }

  // Preserve the existing public-site appearance for first-time visitors.
  return "light";
}

export default function usePublicTheme() {
  const [theme, setTheme] = useState(readPublicTheme);

  useEffect(() => {
    try {
      window.localStorage.setItem(PUBLIC_THEME_KEY, theme);
    } catch {
      // The selected theme still works for the current page session.
    }
  }, [theme]);

  const toggleTheme = () => {
    setTheme((currentTheme) =>
      currentTheme === "dark" ? "light" : "dark"
    );
  };

  return { theme, toggleTheme };
}

