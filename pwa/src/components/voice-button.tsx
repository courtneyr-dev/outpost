import { useEffect, useRef, useState } from 'preact/hooks';

/**
 * Voice input button — drops next to a textarea to dictate content via
 * the browser's Web Speech API.
 *
 * Phase D4. Per-textarea button rather than a global mic so the
 * transcript always lands in the field the user explicitly tapped
 * for. Tap to start, tap again to stop. While recording, a pulsing
 * red dot animates so the user has visible feedback that audio is
 * being captured.
 *
 * Supported in iOS Safari 16.4+, Chrome (any), Edge (any), Samsung
 * Internet. Firefox doesn't ship Web Speech API; the button hides
 * on unsupported browsers via a `supported` feature-detect.
 *
 * The transcript appends to whatever the textarea currently holds —
 * the parent owns the value via the `onTranscript` callback. Spaces
 * between successive transcripts are added if the existing content
 * doesn't end with whitespace.
 *
 * No punctuation post-processing — Web Speech APIs across vendors
 * differ in how they handle commas/periods. Keep what the engine
 * returns; users can edit. Per the C5e design-questions defaults.
 */

interface SpeechRecognitionEventLike {
	resultIndex: number;
	results: ArrayLike<{
		readonly isFinal: boolean;
		readonly 0: { readonly transcript: string };
	}>;
}

interface SpeechRecognitionLike extends EventTarget {
	continuous: boolean;
	interimResults: boolean;
	lang: string;
	start: () => void;
	stop: () => void;
	abort: () => void;
	onresult: ((event: SpeechRecognitionEventLike) => void) | null;
	onerror: ((event: Event) => void) | null;
	onend: (() => void) | null;
}

interface SpeechRecognitionConstructor {
	new (): SpeechRecognitionLike;
}

function get_speech_recognition_ctor(): SpeechRecognitionConstructor | null {
	if (typeof window === 'undefined') return null;
	const w = window as typeof window & {
		SpeechRecognition?: SpeechRecognitionConstructor;
		webkitSpeechRecognition?: SpeechRecognitionConstructor;
	};
	return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export interface VoiceButtonProps {
	onTranscript: (text: string) => void;
	disabled?: boolean;
	/** ARIA label override. Defaults to "Start voice input" / "Stop voice input". */
	label?: string;
}

export function VoiceButton({ onTranscript, disabled, label }: VoiceButtonProps) {
	const [supported, setSupported] = useState(false);
	const [recording, setRecording] = useState(false);
	const recognition_ref = useRef<SpeechRecognitionLike | null>(null);
	// Stash the live `onTranscript` reference. Callers pass inline arrow
	// functions (`onTranscript={(t) => setContent(...)}`), so the prop
	// identity changes every parent render. With `[onTranscript]` as the
	// effect dependency, every keystroke during dictation tore down and
	// rebuilt the SpeechRecognition instance — cutting off the audio
	// session mid-utterance and burning battery. The ref pattern keeps
	// the recognition instance stable for the component's lifetime.
	const on_transcript_ref = useRef(onTranscript);
	on_transcript_ref.current = onTranscript;

	useEffect(() => {
		const Ctor = get_speech_recognition_ctor();
		if (!Ctor) return;
		setSupported(true);
		const r = new Ctor();
		// Continuous mode lets the user dictate multiple sentences. iOS
		// Safari may downgrade this to single-utterance internally, but
		// the start/stop API stays consistent.
		r.continuous = true;
		r.interimResults = false;
		r.lang = navigator.language || 'en-US';
		r.onresult = (event): void => {
			let final = '';
			for (let i = event.resultIndex; i < event.results.length; i++) {
				const result = event.results[i];
				if (result?.isFinal) {
					final += result[0].transcript;
				}
			}
			if (final) {
				on_transcript_ref.current(final);
			}
		};
		r.onend = (): void => {
			setRecording(false);
		};
		r.onerror = (): void => {
			setRecording(false);
		};
		recognition_ref.current = r;

		return (): void => {
			try {
				r.abort();
			} catch (_err) {
				// already-stopped or never-started — fine.
			}
			recognition_ref.current = null;
		};
	}, []);

	if (!supported) return null;

	const toggle = (): void => {
		const r = recognition_ref.current;
		if (!r) return;
		if (recording) {
			try {
				r.stop();
			} catch (_err) {
				// already stopped
			}
			setRecording(false);
			return;
		}
		try {
			r.start();
			setRecording(true);
		} catch (_err) {
			// Some engines throw if start() is called while already running.
			// Reset state and try once.
			setRecording(false);
		}
	};

	const aria_label = label ?? (recording ? 'Stop voice input' : 'Start voice input');

	return (
		<button
			type="button"
			class={
				'outpost-voice-button' + (recording ? ' outpost-voice-button--recording' : '')
			}
			onClick={toggle}
			disabled={disabled}
			aria-label={aria_label}
			aria-pressed={recording}
		>
			<span aria-hidden="true">{recording ? '⏹' : '🎤'}</span>
		</button>
	);
}
