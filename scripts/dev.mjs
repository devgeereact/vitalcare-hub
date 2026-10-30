// `pnpm dev`: once the project is live (local-live.json filled in at the first release) it runs
// `local-live`: your local front end at https://local.<domain>, talking to the LIVE API and live sign-in.
// Before the first release, or with `pnpm dev:local`, it starts the PHP API and Vite together.
// Ctrl+C stops everything.
import { spawn, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";

let live = null;
try {
  live = JSON.parse(readFileSync("local-live.json", "utf8"));
} catch {
  live = null;
}
const isLive =
  live?.host && live?.live?.startsWith("https://") && live?.clerkKey?.startsWith("pk_live_");
if (isLive && !process.argv.includes("--local")) {
  const run = spawnSync("local-live", [], { stdio: "inherit" });
  if (run.error)
    console.error(
      "[dev] local-live is missing: run the Fresh-Mac-Setup shell step, or use pnpm dev:local.",
    );
  process.exit(run.status ?? 1);
}

const API_PORT = "3175";
const children = [];

function start(command, args, label) {
  const child = spawn(command, args, { stdio: "inherit" });
  child.on("exit", (code) => {
    console.log(`[dev] ${label} stopped (exit ${code ?? "signal"})`);
    shutdown(code ?? 0);
  });
  children.push(child);
}

function shutdown(code) {
  for (const child of children) if (child.exitCode === null) child.kill("SIGTERM");
  process.exitCode = code;
}

process.on("SIGINT", () => shutdown(0));
process.on("SIGTERM", () => shutdown(0));

if (spawnSync("php", ["--version"], { stdio: "ignore" }).status === 0) {
  start("php", ["-S", `127.0.0.1:${API_PORT}`, "backend/router.php"], `PHP API :${API_PORT}`);
} else {
  console.warn("[dev] PHP not found — starting the frontend only (brew install php).");
}
start("pnpm", ["exec", "vite"], "Vite");
