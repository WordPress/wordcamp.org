import { Page } from '@wordpress/admin-ui';
import { DataViews } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { ButtonLink, Notice, SwitchControl } from '@wordpress/ui';
import { COMPANY } from './entities';
import { useCompanyList, useOpenCompany } from './hooks';
import './style.scss';
import type { Company } from './types';
import { DEFAULT_LAYOUTS, GROUP_BY_STATUS } from './view';

/**
 * The list of companies. Clicking one opens it in the panel beside the list.
 *
 * @return The stage.
 */
function Stage() {
	const { data, paginationInfo, fields, view, updateView, resetToDefault, error, isLoading } = useCompanyList();
	const [ openId, open ] = useOpenCompany();

	return (
		<Page
			title={ __( 'Sponsor Vetting', 'wordcamporg' ) }
			subTitle={ __( 'Vet each company once, then approve it for the events it sponsors.', 'wordcamporg' ) }
			actions={
				<ButtonLink href={ `post-new.php?post_type=${ COMPANY }` } size="compact">
					{ __( 'Add company', 'wordcamporg' ) }
				</ButtonLink>
			}
			hasPadding={ false }
		>
			{ error ? (
				<Notice.Root intent="error" className="sponsor-vetting__notice">
					<Notice.Title>{ __( 'The companies couldn’t be loaded.', 'wordcamporg' ) }</Notice.Title>
					<Notice.Description>{ error.message }</Notice.Description>
				</Notice.Root>
			) : (
				<DataViews< Company >
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ updateView }
					onReset={ resetToDefault ?? false }
					isLoading={ isLoading }
					paginationInfo={ paginationInfo }
					defaultLayouts={ DEFAULT_LAYOUTS }
					getItemId={ ( item ) => String( item.id ) }
					searchLabel={ __( 'Search companies', 'wordcamporg' ) }
					// DataViews groups rows, but has no control of its own to turn that on.
					header={
						<div className="sponsor-vetting__group-by">
							<SwitchControl
								label={ __( 'Group by status', 'wordcamporg' ) }
								checked={ view.groupBy?.field === 'status' }
								onCheckedChange={ ( checked ) =>
									updateView( {
										...view,
										page: 1,
										groupBy: checked ? GROUP_BY_STATUS : undefined,
									} )
								}
							/>
						</div>
					}
					onClickItem={ ( item ) => open( item.id ) }
					// The list layout opens a company by selecting its row.
					selection={ openId ? [ String( openId ) ] : [] }
					onChangeSelection={ ( [ id ] ) => open( id ? Number( id ) : undefined ) }
				/>
			) }
		</Page>
	);
}

export const stage = Stage;
