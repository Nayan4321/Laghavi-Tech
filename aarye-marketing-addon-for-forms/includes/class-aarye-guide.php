<?php
/**
 * Setup guide: what to paste into each ad platform so every lead shows the campaign, ad set and ad.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guide.
 */
class AARYE_Guide {

	/**
	 * Step-by-step setup per ad platform. "template" is pasted into the platform's URL parameter or
	 * tracking template field; the {...} parts are filled in by the platform itself for every click.
	 *
	 * @return array<string, array{name: string, auto: string, where: string[], template: string, note: string}>
	 */
	public static function platforms() {
		return array(
			'google_ads'    => array(
				'name'     => 'Google Ads',
				'auto'     => __( 'Works without setup: Google adds gclid to every ad click, so leads are marked "Google Ads" automatically. Add the template below to also see the campaign, ad group, keyword and ad.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array(
					__( 'Google Ads → Admin → Account settings → Auto-tagging: make sure it is on.', 'aarye-marketing-addon-for-forms' ),
					__( 'Google Ads → Admin → Account settings → Tracking → "Final URL suffix": paste the text below and save. It applies to every campaign.', 'aarye-marketing-addon-for-forms' ),
					__( 'Optional, for readable names: in each campaign\'s Settings → Campaign URL options, add custom parameters named "_campaign" (campaign name) and "_adgroup" (ad group name). Without them you see Google\'s campaign and ad group numbers.', 'aarye-marketing-addon-for-forms' ),
				),
				'template' => 'utm_source=google&utm_medium=cpc&utm_campaign={_campaign}&utm_adgroup={_adgroup}&utm_term={keyword}&utm_content={creative}&campaign_id={campaignid}&adgroup_id={adgroupid}&matchtype={matchtype}&network={network}&device={device}&placement={placement}',
				'note'     => __( 'If you don\'t add _campaign and _adgroup, use utm_campaign={campaignid}&utm_adgroup={adgroupid} instead: Google only fills in names you add as custom parameters.', 'aarye-marketing-addon-for-forms' ),
			),
			'meta_ads'      => array(
				'name'     => 'Meta Ads (Facebook, Instagram)',
				'auto'     => __( 'Needs the URL parameters below. Facebook and Instagram add fbclid to every link, ads and normal posts alike, so without UTM tags a lead is shown as "Facebook / Instagram" and can\'t be confirmed as an ad.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array(
					__( 'Ads Manager → open the ad (or select all ads and Edit) → "Destination" / "Tracking" → URL parameters → "Build a URL parameter" or paste the text below.', 'aarye-marketing-addon-for-forms' ),
					__( 'Publish. Meta fills in the campaign, ad set and ad names for every click.', 'aarye-marketing-addon-for-forms' ),
				),
				'template' => 'utm_source={{site_source_name}}&utm_medium=paid&utm_campaign={{campaign.name}}&utm_adgroup={{adset.name}}&utm_content={{ad.name}}&campaign_id={{campaign.id}}&adset_id={{adset.id}}&ad_id={{ad.id}}&placement={{placement}}',
				'note'     => __( '{{site_source_name}} becomes fb, ig, msg or an (Facebook, Instagram, Messenger, Audience Network), so you also see where the ad ran. For lead form ads that stay inside Facebook, use the Meta lead connector of your CRM instead: those people never visit your website.', 'aarye-marketing-addon-for-forms' ),
			),
			'microsoft_ads' => array(
				'name'     => 'Microsoft Advertising (Bing)',
				'auto'     => __( 'Works without setup: Microsoft adds msclkid when auto-tagging is on. Add the template to see names.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array(
					__( 'Microsoft Advertising → Settings → Account level options → Auto-tagging of Microsoft Click ID: on.', 'aarye-marketing-addon-for-forms' ),
					__( 'Same page → "Final URL suffix": paste the text below.', 'aarye-marketing-addon-for-forms' ),
				),
				'template' => 'utm_source=bing&utm_medium=cpc&utm_campaign={Campaign}&utm_adgroup={AdGroup}&utm_term={keyword}&utm_content={AdId}&campaign_id={CampaignId}&adgroup_id={AdGroupId}&matchtype={MatchType}&network={Network}&device={Device}',
				'note'     => '',
			),
			'tiktok_ads'    => array(
				'name'     => 'TikTok Ads',
				'auto'     => __( 'Works without setup: TikTok adds ttclid to ad clicks. Add the parameters to see names.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array(
					__( 'TikTok Ads Manager → ad → Destination → URL → "Add URL parameters", or paste the text below at the end of the URL (after ? or &).', 'aarye-marketing-addon-for-forms' ),
				),
				'template' => 'utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_adgroup=__AID_NAME__&utm_content=__CID_NAME__&campaign_id=__CAMPAIGN_ID__&adgroup_id=__AID__&ad_id=__CID__&placement=__PLACEMENT__',
				'note'     => '',
			),
			'linkedin_ads'  => array(
				'name'     => 'LinkedIn Ads',
				'auto'     => __( 'LinkedIn adds li_fat_id only when "Enhanced conversion tracking" is on, so add UTM tags.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array(
					__( 'Campaign Manager → campaign → ad → "Destination URL": add the text below after ? (or & if the URL already has a ?). Type your campaign and ad names in place of the words in capitals.', 'aarye-marketing-addon-for-forms' ),
				),
				'template' => 'utm_source=linkedin&utm_medium=paid&utm_campaign=CAMPAIGN_NAME&utm_adgroup=CAMPAIGN_GROUP&utm_content=AD_NAME',
				'note'     => __( 'If your account offers dynamic UTM parameters in the ad\'s URL tracking settings, use them instead of typing names.', 'aarye-marketing-addon-for-forms' ),
			),
			'x_ads'         => array(
				'name'     => 'X (Twitter) Ads',
				'auto'     => __( 'X adds twclid to ad clicks when its conversion tracking is set up. Add UTM tags to see names.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array( __( 'X Ads → ad → Website URL: add the text below, with your own names in capitals.', 'aarye-marketing-addon-for-forms' ) ),
				'template' => 'utm_source=twitter&utm_medium=paid&utm_campaign=CAMPAIGN_NAME&utm_adgroup=AD_GROUP_NAME&utm_content=AD_NAME',
				'note'     => '',
			),
			'snapchat_ads'  => array(
				'name'     => 'Snapchat Ads',
				'auto'     => __( 'Snapchat adds ScCid to ad clicks. Add UTM tags to see names.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array( __( 'Ads Manager → ad → Attachment → Website URL: add the text below, with your own names in capitals (or Snapchat\'s dynamic URL parameters if your account shows them).', 'aarye-marketing-addon-for-forms' ) ),
				'template' => 'utm_source=snapchat&utm_medium=paid&utm_campaign=CAMPAIGN_NAME&utm_adgroup=AD_SQUAD_NAME&utm_content=AD_NAME',
				'note'     => '',
			),
			'pinterest_ads' => array(
				'name'     => 'Pinterest Ads',
				'auto'     => __( 'Pinterest adds epik to ad clicks. Add UTM tags to see names.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array( __( 'Ads Manager → ad → Destination URL: add the text below, with your own names in capitals (or Pinterest\'s dynamic parameters if offered).', 'aarye-marketing-addon-for-forms' ) ),
				'template' => 'utm_source=pinterest&utm_medium=paid&utm_campaign=CAMPAIGN_NAME&utm_adgroup=AD_GROUP_NAME&utm_content=AD_NAME',
				'note'     => '',
			),
			'reddit_ads'    => array(
				'name'     => 'Reddit Ads',
				'auto'     => __( 'Reddit adds rdt_cid to ad clicks when the Reddit Pixel click ID option is on. Add UTM tags to see names.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array( __( 'Ads Manager → ad → Destination URL: add the text below, with your own names in capitals.', 'aarye-marketing-addon-for-forms' ) ),
				'template' => 'utm_source=reddit&utm_medium=paid&utm_campaign=CAMPAIGN_NAME&utm_adgroup=AD_GROUP_NAME&utm_content=AD_NAME',
				'note'     => '',
			),
			'other'         => array(
				'name'     => __( 'Any other ad network (Quora, Taboola, Outbrain, Amazon, Spotify, programmatic…)', 'aarye-marketing-addon-for-forms' ),
				'auto'     => __( 'Any link with utm_medium=paid (or cpc, display, paid_social…) is counted as an ad. Use the network\'s name as utm_source.', 'aarye-marketing-addon-for-forms' ),
				'where'    => array( __( 'In the ad\'s destination URL, add the text below with your own words in capitals. If the network has "dynamic" or "macro" parameters for campaign and ad names, use those instead.', 'aarye-marketing-addon-for-forms' ) ),
				'template' => 'utm_source=NETWORK&utm_medium=paid&utm_campaign=CAMPAIGN_NAME&utm_adgroup=AD_GROUP_NAME&utm_content=AD_NAME',
				'note'     => __( 'Also use this for QR codes, flyers, SMS and influencer links: for example utm_source=instagram&utm_medium=influencer&utm_campaign=sara-oct.', 'aarye-marketing-addon-for-forms' ),
			),
		);
	}
}
