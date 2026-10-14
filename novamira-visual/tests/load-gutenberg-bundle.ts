/**
 * Child process for read-mode.test.ts: run the built Gutenberg bundle against a stand-in editor and
 * print what it exposed. Usage: bun tests/load-gutenberg-bundle.ts <readMode value>.
 */
const bundle = new URL("../dist/gutenberg.js", import.meta.url);
const readMode = process.argv[2] ?? "";

const page = globalThis as unknown as Record<string, unknown>;
page.window = globalThis;
page.novamiraVisualData = { restUrl: "https://example.test/wp-json/novamira-visual/v1", nonce: "n", readMode };
page.wp = {
	domReady: (callback: () => void) => callback(),
	data: { select: () => ({}) },
};

new Function(await Bun.file(bundle).text())();
await new Promise((resolve) => setTimeout(resolve, 400));

const tools = (page.__novamiraVisualTools ?? {}) as Record<string, unknown>;
const provenance = page.__novamiraVisualToolProvenance as { source: string; tools: unknown } | undefined;
const definitions = (page.__novamiraVisualToolDefinitions ?? []) as Array<{ name: string }>;
console.log(JSON.stringify({
	tools: Object.keys(tools),
	definitions: definitions.map((definition) => definition.name),
	source: provenance?.source ?? null,
	provenanceIsToolMap: provenance?.tools === tools,
}));
