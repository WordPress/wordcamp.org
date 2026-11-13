import { Page } from '@wordpress/admin-ui';
import { DataViews } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { ButtonLink, Notice } from '@wordpress/ui';
import { DEFAULT_LAYOUTS, fields } from './fields';
import { useCompanyList, useOpenCompany } from './hooks';
import './style.scss';
import type { Company } from './types';

/**
 * The list of companies. Clicking one opens it in the panel beside the list.
 *
 * @return The stage.
 */
function Stage() {
	const { data, paginationInfo, view, setView, error, isLoading } = useCompanyList();
	const [ openId, open ] = useOpenCompany();

	return (
		<Page
			title={ __( 'Sponsor Vetting', 'wordcamporg' ) }
			subTitle={ __( 'Vet each company once, then approve it for the events it sponsors.', 'wordcamporg' ) }
			actions={
				<ButtonLink href="post-new.php?post_type=sv_company" size="compact">
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
					onChangeView={ setView }
					isLoading={ isLoading }
					paginationInfo={ paginationInfo }
					defaultLayouts={ DEFAULT_LAYOUTS }
					getItemId={ ( item ) => String( item.id ) }
					searchLabel={ __( 'Search companies', 'wordcamporg' ) }
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
