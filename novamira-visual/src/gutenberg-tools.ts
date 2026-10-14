import { exposeTools } from "./expose-tools";
import { GUTENBERG_TOOL_SOURCE } from "./tool-provenance";
import { makeUseSkillTool } from "./tools/use-skill";
import { listBlockTypesTool } from "./tools/gutenberg/list-block-types";
import { getBlockSchemaTool } from "./tools/gutenberg/get-block-schema";
import { getPageStructureTool } from "./tools/gutenberg/get-page-structure";
import { getBlockAttributesTool } from "./tools/gutenberg/get-block-attributes";
import { getEditorSettingsTool } from "./tools/gutenberg/get-editor-settings";
import { createBlockTool } from "./tools/gutenberg/create-block";
import { updateBlockTool } from "./tools/gutenberg/update-block";
import { deleteBlockTool } from "./tools/gutenberg/delete-block";
import { moveBlockTool } from "./tools/gutenberg/move-block";
import { replaceInnerBlocksTool } from "./tools/gutenberg/replace-inner-blocks";
import { undoTool, redoTool, saveTool } from "./tools/gutenberg/history";

declare const novamiraVisualData: { readMode?: boolean | string } | undefined;

/** Tools that only read editor state. */
export const gutenbergReadOnlyTools = [
	listBlockTypesTool,
	getBlockSchemaTool,
	getPageStructureTool,
	getBlockAttributesTool,
	getEditorSettingsTool,
];

export const gutenbergTools = [
	...gutenbergReadOnlyTools,
	createBlockTool,
	updateBlockTool,
	deleteBlockTool,
	moveBlockTool,
	replaceInnerBlocksTool,
	undoTool,
	redoTool,
	saveTool,
	makeUseSkillTool("gutenberg"),
];

/** Whether Novamira read mode was active when the editor loaded. wp_localize_script sends true as "1". */
function isReadMode(): boolean {
	if (typeof novamiraVisualData === "undefined") return false;
	return novamiraVisualData.readMode === true || novamiraVisualData.readMode === "1";
}

/** Expose the Gutenberg tools. Novamira Visual does not work in read mode, so then no tool is exposed. */
export function exposeGutenbergTools(): void {
	if (isReadMode()) return;
	exposeTools(gutenbergTools, GUTENBERG_TOOL_SOURCE);
}
