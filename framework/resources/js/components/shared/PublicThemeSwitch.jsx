import "../../../css/shared/public-theme-switch.css";

function PublicThemeSwitch({ theme, onToggle }) {
  const targetTheme = theme === "dark" ? "light" : "dark";

  return (
    <button
      type="button"
      className="public-theme-switch"
      data-theme={theme}
      onClick={onToggle}
      aria-label={`Switch to ${targetTheme} mode`}
      title={`Switch to ${targetTheme} mode`}
    >
      <span
        className="public-theme-switch__thumb"
        aria-hidden="true"
      />

      <span
        className="public-theme-switch__icon public-theme-switch__icon--sun"
        aria-hidden="true"
      >
        <svg
          viewBox="0 0 24 24"
          width="18"
          height="18"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.9"
          strokeLinecap="round"
        >
          <circle cx="12" cy="12" r="3.5" />
          <path d="M12 2.5v2" />
          <path d="M12 19.5v2" />
          <path d="M4.5 12h-2" />
          <path d="M21.5 12h-2" />
          <path d="m5.3 5.3 1.4 1.4" />
          <path d="m17.3 17.3 1.4 1.4" />
          <path d="m18.7 5.3-1.4 1.4" />
          <path d="m6.7 17.3-1.4 1.4" />
        </svg>
      </span>

      <span
        className="public-theme-switch__icon public-theme-switch__icon--moon"
        aria-hidden="true"
      >
        <svg
          viewBox="0 0 24 24"
          width="18"
          height="18"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.9"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M20.2 15.2A8 8 0 0 1 8.8 3.8 8.5 8.5 0 1 0 20.2 15.2Z" />
        </svg>
      </span>
    </button>
  );
}

export default PublicThemeSwitch;
