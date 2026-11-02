import { setLanguage, t, useLanguage } from "@/lib/i18n";
import { Button } from "@/components/ui/button";

export function LanguageSwitcher() {
  const language = useLanguage();
  return (
    <div
      className="language-switcher"
      role="group"
      aria-label={t("Interface language")}
    >
      {(["id", "en"] as const).map((value) => (
        <Button
          key={value}
          type="button"
          size="sm"
          variant="ghost"
          aria-label={value === "id" ? "Bahasa Indonesia" : "English"}
          aria-pressed={language === value}
          onClick={() => setLanguage(value)}
        >
          {value.toUpperCase()}
        </Button>
      ))}
    </div>
  );
}
