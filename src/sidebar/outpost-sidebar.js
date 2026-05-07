/**
 * Outpost PluginSidebar component (G3.5c).
 *
 * Single PluginSidebar that future Outpost panels hang off of. The
 * placeholder card proves the build pipeline works end-to-end. Real UI
 * components (POSSE destination selector, fetch-recent picker, etc.)
 * land in follow-up PRs as additional `<PanelBody>` sections inside
 * this sidebar.
 *
 * @file
 */

import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { Card, CardBody, PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { share } from '@wordpress/icons';

import './outpost-sidebar.scss';

/**
 * Render the Outpost PluginSidebar.
 *
 * @return {JSX.Element} The sidebar tree.
 */
export function OutpostSidebar() {
	return (
		<>
			<PluginSidebarMoreMenuItem target="outpost-sidebar" icon={ share }>
				{ __( 'Outpost', 'outpost' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="outpost-sidebar"
				title={ __( 'Outpost', 'outpost' ) }
				icon={ share }
			>
				<PanelBody initialOpen>
					<Card>
						<CardBody>
							{ __(
								'Outpost sidebar is loaded. Components will appear here as features ship.',
								'outpost'
							) }
						</CardBody>
					</Card>
				</PanelBody>
			</PluginSidebar>
		</>
	);
}
