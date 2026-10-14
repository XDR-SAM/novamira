import type { AgentTool } from "./tool-types";

declare global {
	interface Window {
		__novamiraVisualTools?: Record<string, (params?: unknown) => Promise<AgentToolResultLike>>;
		__novamiraVisualToolProvenance?: { source: string; tools: Record<string, unknown> };
		__novamiraVisualToolDefinitions?: Array<{
			name: string;
			label?: string;
			description?: string;
			parameters?: unknown;
		}>;
	}
}

type AgentToolResultLike = Awaited<ReturnType<AgentTool["execute"]>>;

/**
 * Expose editor tools to the Visual workspace. A source tags the tool map, so the workspace can verify
 * which bundle the tools on a page come from.
 */
export function exposeTools(tools: AgentTool[], source?: string): void {
	const toolMap: Record<string, (params?: unknown) => Promise<AgentToolResultLike>> = {};

	for (const tool of tools) {
		toolMap[tool.name] = async (params: unknown = {}) => {
			const normalizedParams = normalizeArgAliases(params);
			const missing = getMissingRequiredParams(tool.parameters, normalizedParams);
			if (missing.length > 0) {
				throw new Error(
					`Missing required args for ${tool.name}: ${missing.join(", ")}. ` +
					`Call workspace_request with method "workspace_call_page_tool" and params containing args, or if your client does not expose nested args, use toolName: ` +
					`${JSON.stringify(`${tool.name} ${JSON.stringify(exampleArgs(tool.parameters))}`)}.`,
				);
			}

			return tool.execute(tool.name, normalizedParams);
		};
	}

	toolMap.batch_call = async (params: unknown = {}) => {
		const normalized = normalizeArgAliases(params);
		const input = normalized && typeof normalized === "object" && !Array.isArray(normalized)
			? normalized as { calls?: unknown[]; stop_on_error?: unknown; rollback_on_error?: unknown; include_call_details?: unknown; max_text_chars?: unknown }
			: {};
		const calls = Array.isArray(input.calls) ? input.calls : [];
		if (calls.length === 0) throw new Error("batch_call requires a non-empty calls array.");

		const lines: string[] = [];
		const details: Array<Record<string, unknown>> = [];
		const refs: Record<string, unknown> = {};
		const stopOnError = input.stop_on_error !== false;
		const rollbackOnError = stopOnError && input.rollback_on_error !== false;
		const includeCallDetails = input.include_call_details === true;
		const maxTextChars = typeof input.max_text_chars === "number" && Number.isFinite(input.max_text_chars)
			? Math.max(80, Math.min(Math.floor(input.max_text_chars), 4000))
			: 400;
		let successfulMutationCount = 0;
		const rollbackActions: RollbackAction[] = [];
		let rolledBack = false;

		for (let i = 0; i < calls.length; i++) {
			const call = calls[i] && typeof calls[i] === "object" && !Array.isArray(calls[i])
				? calls[i] as { tool?: unknown; toolName?: unknown; name?: unknown; args?: unknown; id?: unknown }
				: {};
			const toolName = firstString(call.tool, call.toolName, call.name);
			if (!toolName || toolName === "batch_call" || typeof toolMap[toolName] !== "function") {
				const message = `batch_call[${i}] has unavailable tool: ${toolName || "<missing>"}`;
				lines.push(`${i + 1}. ${toolName || "<missing>"}: ERROR ${truncate(message, 400)}`);
				details.push({ index: i, tool: toolName, id: call.id, ok: false, error: message });
				if (stopOnError) {
					rolledBack = await rollbackMutations(toolMap, successfulMutationCount, rollbackActions, rollbackOnError, lines);
					break;
				}
				continue;
			}

			try {
				const result = await toolMap[toolName](resolveRefs(call.args ?? {}, refs));
				const refValue = pickRefValue(result);
				if (typeof call.id === "string" && call.id.trim() && refValue !== undefined) refs[call.id] = refValue;
				if (isUndoableMutation(toolName)) {
					successfulMutationCount++;
					const rollbackAction = getRollbackAction(toolName, refValue, toolMap, result);
					if (rollbackAction) rollbackActions.push(rollbackAction);
				}

				const text = result.content?.[0]?.text ?? "ok";
				lines.push(`${i + 1}. ${toolName}: ${truncate(text, maxTextChars)}`);
				const detail: Record<string, unknown> = { index: i, tool: toolName, id: call.id, ok: true, ref: refValue };
				if (includeCallDetails) detail.details = result.details;
				details.push(detail);
			} catch (error) {
				let message = error instanceof Error ? error.message : String(error);
				if (typeof call.id === "string" && message.startsWith("Missing required args")) {
					message += " In batch_call, put target IDs inside args (for example args.element_id); the call id field only creates a reference alias for later '$id' use.";
				}
				lines.push(`${i + 1}. ${toolName}: ERROR ${truncate(message, 400)}`);
				details.push({ index: i, tool: toolName, id: call.id, ok: false, error: message });
				if (stopOnError) {
					rolledBack = await rollbackMutations(toolMap, successfulMutationCount, rollbackActions, rollbackOnError, lines);
					break;
				}
			}
		}

		return {
			content: [{ type: "text" as const, text: lines.join("\n") }],
			details: compactBatchDetails(details, refs, rolledBack, includeCallDetails),
		};
	};

	window.__novamiraVisualTools = toolMap;
	if (source !== undefined) window.__novamiraVisualToolProvenance = { source, tools: toolMap };
	window.__novamiraVisualToolDefinitions = [
		...tools.map((tool) => ({
			name: tool.name,
			label: tool.label,
			description: tool.description,
			parameters: tool.parameters,
		})),
		{
			name: "batch_call",
			label: "Batch Calls",
			description:
				"Run multiple editor tool calls sequentially in one request. Put each tool's required target IDs inside args. Use id only as an alias for the returned ID, then reference it in later args as '$id'. Prefer this for multi-step edits and schema discovery. Batch initial discovery, then batch related structure/content/style mutations rather than one huge fragile batch.",
			parameters: {
				type: "object",
				required: ["calls"],
				properties: {
					calls: {
						type: "array",
						items: {
							type: "object",
							required: ["tool"],
							properties: {
								tool: { type: "string", description: "Tool name to call." },
								args: { type: "object", description: "Tool arguments. String values like '$hero' reference earlier call ids." },
								id: { type: "string", description: "Optional alias for this call's returned ID; not a target element/block/widget ID." },
							},
						},
					},
					stop_on_error: { type: "boolean", description: "Stop after the first failed call. Default true; use false for independent discovery calls." },
					rollback_on_error: { type: "boolean", description: "When stop_on_error is true, undo successful create/update/delete/move calls after a later failure. Default true." },
					include_call_details: { type: "boolean", description: "Include each call's full details object only when debugging or auditing. Default false keeps output compact; refs are still returned for aliased create calls." },
					max_text_chars: { type: "number", description: "Max text returned per call in the batch summary. Default 400, max 4000." },
				},
			},
		},
	];
}

function compactBatchDetails(
	calls: Array<Record<string, unknown>>,
	refs: Record<string, unknown>,
	rolledBack: boolean,
	includeCallDetails: boolean,
): Record<string, unknown> {
	const failures = calls.filter((call) => call.ok === false);
	const result: Record<string, unknown> = {
		count: calls.length,
		ok: failures.length === 0,
		rolledBack,
	};
	if (Object.keys(refs).length > 0) result.refs = refs;
	if (failures.length > 0) result.failures = failures;
	if (includeCallDetails) result.calls = calls;
	return result;
}

type RollbackAction = { tool: string; args: Record<string, unknown> };

async function rollbackMutations(
	toolMap: Record<string, (params?: unknown) => Promise<AgentToolResultLike>>,
	count: number,
	actions: RollbackAction[],
	enabled: boolean,
	lines: string[],
): Promise<boolean> {
	if (!enabled || count === 0) return false;
	let directRollbacks = 0;
	let undoCount = count;

	for (const action of [...actions].reverse()) {
		const rollbackTool = toolMap[action.tool];
		if (typeof rollbackTool !== "function") continue;
		try {
			await rollbackTool(action.args);
			directRollbacks++;
			undoCount--;
		} catch (error) {
			const message = error instanceof Error ? error.message : String(error);
			lines.push(`Direct rollback via ${action.tool} failed: ${truncate(message, 400)}`);
		}
	}

	if (undoCount <= 0) {
		lines.push(`Rolled back ${directRollbacks} successful mutation${directRollbacks === 1 ? "" : "s"}.`);
		return directRollbacks > 0;
	}

	if (typeof toolMap.undo !== "function") return directRollbacks > 0;
	let undone = 0;
	try {
		for (let i = 0; i < undoCount; i++) {
			await toolMap.undo({});
			undone++;
		}
		const total = directRollbacks + undone;
		lines.push(`Rolled back ${total} successful mutation${total === 1 ? "" : "s"}.`);
		return true;
	} catch (error) {
		const message = error instanceof Error ? error.message : String(error);
		lines.push(`Rollback stopped after ${directRollbacks} direct and ${undone}/${undoCount} undo rollbacks: ${truncate(message, 400)}`);
		return directRollbacks + undone > 0;
	}
}

function getRollbackAction(
	toolName: string,
	refValue: unknown,
	toolMap: Record<string, (params?: unknown) => Promise<AgentToolResultLike>>,
	result: AgentToolResultLike,
): RollbackAction | null {
	if (typeof refValue !== "string" || !refValue) return null;
	if (toolName === "create_element" && typeof toolMap.delete_element === "function") {
		const details = result.details && typeof result.details === "object" && !Array.isArray(result.details)
			? result.details as { autoCreatedContainerId?: unknown }
			: null;
		if (typeof details?.autoCreatedContainerId === "string" && details.autoCreatedContainerId) {
			return { tool: "delete_element", args: { element_id: details.autoCreatedContainerId } };
		}
		return { tool: "delete_element", args: { element_id: refValue } };
	}
	if (toolName === "create_block" && typeof toolMap.delete_block === "function") {
		return { tool: "delete_block", args: { client_id: refValue } };
	}
	return null;
}

function isUndoableMutation(toolName: string): boolean {
	return [
		"create_element",
		"update_element",
		"update_settings",
		"delete_element",
		"move_element",
		"create_block",
		"update_block",
		"delete_block",
		"move_block",
		"replace_inner_blocks",
	].includes(toolName);
}

function firstString(...values: unknown[]): string {
	for (const value of values) {
		if (typeof value === "string" && value.trim()) return value;
	}
	return "";
}

function normalizeArgAliases(params: unknown): unknown {
	if (!params || typeof params !== "object" || Array.isArray(params)) return params;
	const input = params as Record<string, unknown>;
	const aliases: Record<string, string> = {
		blockName: "block_name",
		block_name: "block_name",
		clientId: "client_id",
		client_id: "client_id",
		controlKey: "control_key",
		control_key: "control_key",
		controlNames: "control_names",
		control_names: "control_names",
		elementId: "element_id",
		element_id: "element_id",
		elementName: "element_name",
		element_name: "element_name",
		elementType: "element_type",
		element_type: "element_type",
		includeResponsive: "include_responsive",
		include_responsive: "include_responsive",
		includeSchema: "include_schema",
		include_schema: "include_schema",
		includeText: "include_text",
		include_text: "include_text",
		innerBlocks: "inner_blocks",
		inner_blocks: "inner_blocks",
		parentId: "parent_id",
		parent_id: "parent_id",
		rollbackOnError: "rollback_on_error",
		rollback_on_error: "rollback_on_error",
		stopOnError: "stop_on_error",
		stop_on_error: "stop_on_error",
		targetParentClientId: "target_parent_client_id",
		target_parent_client_id: "target_parent_client_id",
		targetParentId: "target_parent_id",
		target_parent_id: "target_parent_id",
		widgetType: "widget_type",
		widget_type: "widget_type",
	};

	const out: Record<string, unknown> = {};
	for (const [key, value] of Object.entries(input)) {
		const canonical = aliases[key] ?? key;
		if (out[canonical] === undefined) out[canonical] = value;
	}
	return out;
}

function getMissingRequiredParams(parameters: unknown, params: unknown): string[] {
	const schema = parameters && typeof parameters === "object" && !Array.isArray(parameters)
		? parameters as { required?: unknown }
		: null;
	const required = Array.isArray(schema?.required) ? schema.required.filter((key) => typeof key === "string") : [];
	if (required.length === 0) return [];

	const input = params && typeof params === "object" && !Array.isArray(params)
		? params as Record<string, unknown>
		: {};
	return required.filter((key) => input[key] === undefined || input[key] === null || input[key] === "");
}

function exampleArgs(parameters: unknown): Record<string, unknown> {
	const schema = parameters && typeof parameters === "object" && !Array.isArray(parameters)
		? parameters as { required?: unknown; properties?: unknown }
		: null;
	const required = Array.isArray(schema?.required) ? schema.required.filter((key) => typeof key === "string") : [];
	const properties = schema?.properties && typeof schema.properties === "object" && !Array.isArray(schema.properties)
		? schema.properties as Record<string, { type?: unknown }>
		: {};

	return Object.fromEntries(required.map((key) => [key, exampleValue(properties[key]?.type)]));
}

function exampleValue(type: unknown): unknown {
	if (type === "number" || type === "integer") return 0;
	if (type === "boolean") return true;
	if (type === "array") return [];
	if (type === "object") return {};
	return "...";
}

function resolveRefs(value: unknown, refs: Record<string, unknown>): unknown {
	if (typeof value === "string" && value.startsWith("$")) {
		const key = value.slice(1);
		return Object.prototype.hasOwnProperty.call(refs, key) ? refs[key] : value;
	}
	if (Array.isArray(value)) return value.map((item) => resolveRefs(item, refs));
	if (value && typeof value === "object") {
		return Object.fromEntries(
			Object.entries(value as Record<string, unknown>).map(([key, item]) => [key, resolveRefs(item, refs)]),
		);
	}
	return value;
}

function pickRefValue(result: AgentToolResultLike): unknown {
	const details = result.details as Record<string, unknown> | undefined;
	if (!details || typeof details !== "object") return undefined;
	for (const key of ["id", "element_id", "block_id", "clientId", "widget_id"]) {
		if (details[key] !== undefined) return details[key];
	}
	return undefined;
}

function truncate(text: string, max: number): string {
	return text.length <= max ? text : `${text.slice(0, max - 3)}...`;
}
