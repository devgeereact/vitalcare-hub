#!/usr/bin/env node
// Voice and trace check for everything that can reach a visitor (docs/DESIGN.md, "Voice").
// Fails on: em-dashes, banned words, hollow healthcare phrases, and traces of the old
// template or earlier systems. Zero tolerance, no exceptions list.
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { parse } from "yaml";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");

const BANNED_WORDS = [
  "delve",
  "tapestry",
  "testament",
  "showcase",
  "showcases",
  "showcasing",
  "pivotal",
  "synergy",
  "seamless",
  "seamlessly",
  "leverage",
  "leveraging",
  "holistic",
  "game-changer",
  "transformative",
  "innovative",
  "unique",
  "comprehensive",
  "bespoke",
  "streamline",
  "streamlined",
  "facilitate",
  "empower",
  "empowering",
  "world-class",
  "state-of-the-art",
  "cutting-edge",
  "best-in-class",
  "trusted by",
  "leading provider",
  "fully compliant",
  "guaranteed",
];
const BANNED_PHRASES = [
  "passionate about patient care",
  "committed to excellence",
  "going the extra mile",
  "making a difference",
  "dedicated team of professionals",
];
const TRACES = ["acme", "pulse-ui", "pulseui", "codervent", "supabase", "unsplash", "lovable"];
const AMERICAN = [
  "organization",
  "program ",
  "center ",
  "color ",
  "license ",
  "authorized",
  "recognized",
  "behavior",
];

const SCAN_DIRS = ["src", "public"];
const SCAN_FILES = ["index.html"];
const EXT = new Set([".ts", ".tsx", ".css", ".html", ".txt", ".webmanifest", ".json", ".svg"]);
const SKIP = (p) =>
  p.includes("/generated/") ||
  p.endsWith(".test.ts") ||
  p.endsWith(".test.tsx") ||
  p.includes("/licences/") ||
  p.includes("/logos/") ||
  p.includes("/icons/");

function* walk(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) yield* walk(p);
    else yield p;
  }
}

const files = [];
for (const d of SCAN_DIRS) {
  try {
    for (const f of walk(join(root, d)))
      if (EXT.has(f.slice(f.lastIndexOf("."))) && !SKIP(f)) files.push(f);
  } catch {
    /* directory absent */
  }
}
for (const f of SCAN_FILES) files.push(join(root, f));

const findings = [];
const lintText = (label, text) => {
  const lower = text.toLowerCase();
  if (text.includes("—")) findings.push(`${label}: em-dash`);
  for (const w of BANNED_WORDS) {
    if (new RegExp(`(^|[^a-z])${w.replace(/[-]/g, "\\-")}([^a-z]|$)`, "i").test(lower))
      findings.push(`${label}: banned word "${w}"`);
  }
  for (const p of BANNED_PHRASES)
    if (lower.includes(p)) findings.push(`${label}: banned phrase "${p}"`);
  for (const t of TRACES)
    if (lower.includes(t)) findings.push(`${label}: trace of old system "${t}"`);
  for (const a of AMERICAN)
    if (lower.includes(a)) findings.push(`${label}: American spelling "${a.trim()}"`);
};

for (const f of files) lintText(relative(root, f), readFileSync(f, "utf8"));

// The register's own wording (PROVEN text) is held to the same rules.
const claims = parse(readFileSync(join(root, "docs/claims.yml"), "utf8")) ?? {};
for (const [k, v] of Object.entries(claims))
  if (v?.status === "PROVEN") lintText(`docs/claims.yml:${k}`, String(v.text ?? ""));

if (findings.length) {
  console.error("Copy check failed:\n- " + findings.join("\n- "));
  process.exit(1);
}
console.log(`copy ok: ${files.length} files and ${Object.keys(claims).length} claims checked`);
