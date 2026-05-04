/**
 * SyndicationCaptureForm — the inline URL prompt for one pending entry.
 *
 * State machine:
 *
 *   idle → submitting → recorded ✓
 *                    → mismatch_warning (user can retry with confirm)
 *                    → error
 *
 * Pure presentational component — all behavior comes through the
 * `submit` prop (an injected function from `capture-api.ts`).
 *
 * Hard Contract: zero color values. Structural classes only.
 */

import { h, type FunctionComponent } from 'preact';
import { useState } from 'preact/hooks';
import { submit_capture, type CaptureApiEnvironment, type CaptureResponse } from '../lib/manual-share/capture-api';

export interface SyndicationCaptureFormProps {
	post_id: number;
	audit_log_id: string;
	platform_id: string;
	platform_label: string;
	api_env: CaptureApiEnvironment;
	on_recorded: ( silo_url: string ) => void;
	on_cancel: () => void;
	/**
	 * Optional override of the submit fn. Tests inject a stub instead
	 * of stubbing fetch globally.
	 */
	submit_fn?: ( input: Parameters<typeof submit_capture>[0], env: CaptureApiEnvironment ) => Promise<CaptureResponse>;
}

type FormState =
	| { kind: 'idle' }
	| { kind: 'submitting' }
	| { kind: 'mismatch'; message: string }
	| { kind: 'error'; message: string };

export const SyndicationCaptureForm: FunctionComponent<SyndicationCaptureFormProps> = ( {
	post_id,
	audit_log_id,
	platform_id,
	platform_label,
	api_env,
	on_recorded,
	on_cancel,
	submit_fn = submit_capture,
} ) => {
	const [ url, set_url ]     = useState( '' );
	const [ state, set_state ] = useState<FormState>( { kind: 'idle' } );

	const handle_submit = async ( confirm_mismatch: boolean ) => {
		const trimmed = url.trim();
		if ( '' === trimmed ) {
			set_state( { kind: 'error', message: 'Please paste a URL.' } );
			return;
		}

		set_state( { kind: 'submitting' } );
		const response = await submit_fn(
			{
				post_id,
				audit_log_id,
				silo_url:         trimmed,
				confirm_mismatch: confirm_mismatch,
			},
			api_env,
		);

		if ( response.status === 'recorded' ) {
			on_recorded( response.silo_url );
			return;
		}
		if ( response.status === 'mismatch_warning' ) {
			set_state( { kind: 'mismatch', message: response.message } );
			return;
		}
		set_state( { kind: 'error', message: response.message } );
	};

	const submitting = state.kind === 'submitting';

	return (
		<form
			class="outpost-syndication-capture-form"
			onSubmit={ ( e ) => {
				e.preventDefault();
				void handle_submit( false );
			} }
		>
			<label class="outpost-syndication-capture-form__label">
				<span class="outpost-syndication-capture-form__label-text">
					Paste the URL of your { platform_label } post
				</span>
				<input
					type="url"
					class="outpost-syndication-capture-form__input"
					value={ url }
					onInput={ ( e ) => set_url( ( e.currentTarget as HTMLInputElement ).value ) }
					placeholder="https://"
					inputMode="url"
					autoComplete="off"
					autoCapitalize="off"
					spellcheck={ false }
					disabled={ submitting }
					required
				/>
			</label>

			{ state.kind === 'mismatch' && (
				<div class="outpost-syndication-capture-form__warning" role="alert">
					<p>{ state.message }</p>
					<button
						type="button"
						class="outpost-syndication-capture-form__action outpost-syndication-capture-form__action--confirm"
						data-action="confirm-mismatch"
						onClick={ () => void handle_submit( true ) }
						disabled={ submitting }
					>
						Save anyway
					</button>
				</div>
			) }

			{ state.kind === 'error' && (
				<p class="outpost-syndication-capture-form__error" role="alert">
					{ state.message }
				</p>
			) }

			<div class="outpost-syndication-capture-form__actions">
				<button
					type="submit"
					class="outpost-syndication-capture-form__action outpost-syndication-capture-form__action--save"
					data-action="save"
					disabled={ submitting }
				>
					{ submitting ? 'Saving…' : 'Save' }
				</button>
				<button
					type="button"
					class="outpost-syndication-capture-form__action outpost-syndication-capture-form__action--cancel"
					data-action="cancel"
					onClick={ on_cancel }
					disabled={ submitting }
				>
					Cancel
				</button>
			</div>
			{ /* hidden field carrying platform_id so the DOM has it
			     for accessibility tools to associate the input. */ }
			<input type="hidden" data-platform-id={ platform_id } />
		</form>
	);
};
