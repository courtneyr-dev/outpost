/**
 * Tests for the block-editor pending-syndication notice.
 *
 * Uses the injected-environment seam rather than touching `window` or
 * the real data registry, matching the pattern the PWA modules use.
 *
 * NOTE: CI does not run this suite — `npm run test:wp:unit` is not in
 * .github/workflows/ci.yml. The enforced contract lives in
 * tests/unit/PendingSyndicationNoticeTest.php.
 */

import {
	buildNoticeContent,
	isRenderablePayload,
	registerPendingSyndicationNotice,
	PENDING_SYNDICATION_NOTICE_ID,
} from '../pending-syndication-notice.js';

const payload = () => ( {
	postId: 42,
	count: 2,
	message: 'This post has 2 pending syndications.',
	platforms: [
		{
			id: 'instagram-feed',
			label: 'Instagram',
			firedAt: '2026-08-21T10:00:00+00:00',
			firedHuman: 'fired 2 hours ago',
			strategy: 'navigator_share',
		},
		{
			id: 'linkedin',
			label: 'LinkedIn',
			firedAt: '2026-08-21T10:00:00+00:00',
			firedHuman: 'fired 2 hours ago',
			strategy: 'navigator_share',
		},
	],
	composerUrl: 'https://example.com/post/',
	actionLabel: 'Open the Outpost composer',
} );

function stubDispatch() {
	const createNotice = jest.fn();
	return { createNotice, dispatch: () => ( { createNotice } ) };
}

describe( 'isRenderablePayload', () => {
	it( 'rejects a missing payload', () => {
		expect( isRenderablePayload( undefined ) ).toBe( false );
	} );

	it( 'rejects a payload with no platforms', () => {
		expect( isRenderablePayload( { ...payload(), platforms: [] } ) ).toBe(
			false
		);
	} );

	it( 'accepts a payload with at least one platform', () => {
		expect( isRenderablePayload( payload() ) ).toBe( true );
	} );
} );

describe( 'buildNoticeContent', () => {
	it( 'appends the platform list to the message', () => {
		expect( buildNoticeContent( payload() ) ).toBe(
			'This post has 2 pending syndications. ' +
				'Instagram — fired 2 hours ago, LinkedIn — fired 2 hours ago'
		);
	} );

	it( 'omits the relative time when PHP could not resolve one', () => {
		const bare = payload();
		bare.platforms = [ { label: 'Instagram', firedHuman: '' } ];
		expect( buildNoticeContent( bare ) ).toBe(
			'This post has 2 pending syndications. Instagram'
		);
	} );
} );

describe( 'registerPendingSyndicationNotice', () => {
	it( 'creates a dismissible notice with a composer link', () => {
		const { createNotice, dispatch } = stubDispatch();

		const created = registerPendingSyndicationNotice( {
			scope: { outpostPendingSyndication: payload() },
			dispatch,
		} );

		expect( created ).toBe( true );
		expect( createNotice ).toHaveBeenCalledWith(
			'warning',
			expect.stringContaining( 'Instagram' ),
			{
				id: PENDING_SYNDICATION_NOTICE_ID,
				isDismissible: true,
				actions: [
					{
						label: 'Open the Outpost composer',
						url: 'https://example.com/post/',
					},
				],
			}
		);
	} );

	it( 'does nothing when PHP attached no payload', () => {
		const { createNotice, dispatch } = stubDispatch();

		const created = registerPendingSyndicationNotice( {
			scope: {},
			dispatch,
		} );

		expect( created ).toBe( false );
		expect( createNotice ).not.toHaveBeenCalled();
	} );
} );
