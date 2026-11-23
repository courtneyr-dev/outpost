import type { Meta, StoryObj } from '@storybook/preact-vite';
import { useState } from 'preact/hooks';
import { GeocodePicker } from './geocode-picker';
import type { GeocodeResult } from '../lib/geocode';
import { noop, source, token } from '../../../.storybook/fixtures';

const BIG_BEND: GeocodeResult = {
	lat: 29.25,
	lon: -103.25,
	displayName: 'Big Bend National Park, Brewster County, Texas, United States',
	type: 'park',
};

const meta = {
	title: 'Components/GeocodePicker',
	component: GeocodePicker,
	parameters: source(
		'pwa/src/components/geocode-picker.tsx',
		'Location widget: a free-text venue name, plus optional OpenStreetMap coordinates from the site\'s Nominatim proxy in a disclosure. Picking a result fills the venue name, which stays editable, and the parent posts `location` as a `geo:` URI and the venue as `mp-place-name`. The parent owns both values, so these stories wrap it in local state. Search states are not shown because `lib/geocode.ts` calls the global `fetch` directly.',
	),
	args: {
		idPrefix: 'story-geo',
		accessToken: token.accessToken,
		picked: null,
		onPick: noop,
		onClear: noop,
		venueName: '',
		onVenueChange: noop,
	},
	render: function Controlled(args) {
		const [picked, setPicked] = useState<GeocodeResult | null>(args.picked);
		const [venue, setVenue] = useState(args.venueName);
		return (
			<GeocodePicker
				{...args}
				picked={picked}
				onPick={setPicked}
				onClear={(): void => setPicked(null)}
				venueName={venue}
				onVenueChange={setVenue}
			/>
		);
	},
} satisfies Meta<typeof GeocodePicker>;

export default meta;
type Story = StoryObj<typeof meta>;

export const VenueOnly: Story = { args: { venueName: 'My kitchen' } };

export const PickedResult: Story = { args: { picked: BIG_BEND, venueName: 'Big Bend' } };

export const Disabled: Story = { args: { picked: BIG_BEND, venueName: 'Big Bend', disabled: true } };
