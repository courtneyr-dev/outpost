/**
 * One-line hint under a disabled publish button naming the required
 * fields that are still empty (mockup review P4). Empty when nothing is
 * missing, so the caller can drop the button's description.
 */
export function missing_fields_hint(fields: readonly string[]): string {
	if (fields.length === 0) return '';
	if (fields.length === 1) return `Still needed: ${fields[0] ?? ''}.`;
	if (fields.length === 2) return `Still needed: ${fields[0] ?? ''} and ${fields[1] ?? ''}.`;
	const head = fields.slice(0, -1).join(', ');
	return `Still needed: ${head}, and ${fields[fields.length - 1] ?? ''}.`;
}
