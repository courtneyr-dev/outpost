/**
 * SnoozeMenu — picker for snooze duration on a single audit log entry
 * or for the "snooze all" rollup action.
 *
 * Four options the user can pick:
 *   - 1 day  ('P1D')
 *   - 3 days ('P3D')
 *   - 1 week ('P7D')
 *   - Forever ('forever' → server resolves to abandoned sentinel)
 *
 * Pure presentational — `on_pick` callback receives the chosen
 * duration string the caller passes to the dismiss-reminder /
 * snooze-all endpoints.
 */

import { h, type FunctionComponent } from 'preact';

export type SnoozeDuration = 'P1D' | 'P3D' | 'P7D' | 'forever';

export interface SnoozeOption {
	value: SnoozeDuration;
	label: string;
}

export const DEFAULT_SNOOZE_OPTIONS: SnoozeOption[] = [
	{ value: 'P1D',     label: '1 day' },
	{ value: 'P3D',     label: '3 days' },
	{ value: 'P7D',     label: '1 week' },
	{ value: 'forever', label: 'Forever (mark abandoned)' },
];

export interface SnoozeMenuProps {
	on_pick: ( duration: SnoozeDuration ) => void;
	on_cancel?: () => void;
	options?: SnoozeOption[];
	disabled?: boolean;
}

export const SnoozeMenu: FunctionComponent<SnoozeMenuProps> = ( {
	on_pick,
	on_cancel,
	options = DEFAULT_SNOOZE_OPTIONS,
	disabled = false,
} ) => {
	return (
		<div
			class="outpost-snooze-menu"
			role="menu"
			aria-label="Snooze duration"
		>
			<ul class="outpost-snooze-menu__list">
				{ options.map( ( option ) => (
					<li key={ option.value } class="outpost-snooze-menu__item">
						<button
							type="button"
							class="outpost-snooze-menu__option"
							role="menuitem"
							data-snooze-value={ option.value }
							disabled={ disabled }
							onClick={ () => on_pick( option.value ) }
						>
							{ option.label }
						</button>
					</li>
				) ) }
			</ul>
			{ on_cancel && (
				<button
					type="button"
					class="outpost-snooze-menu__cancel"
					data-action="cancel"
					onClick={ on_cancel }
					disabled={ disabled }
				>
					Cancel
				</button>
			) }
		</div>
	);
};
