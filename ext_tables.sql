#
# Add fields for table 'pages'
#
CREATE TABLE pages (
	tx_seo_titletag tinytext,
	tx_seo_canonicaltag tinytext,
	tx_seo_robots tinytext,
	tx_seo_no_sitemap int(1) DEFAULT '0' NOT NULL
);

#
# Add fields for table 'pages_language_overlay'
#
CREATE TABLE pages_language_overlay (
	tx_seo_titletag tinytext,
	tx_seo_canonicaltag tinytext,
	tx_seo_robots tinytext
);
