<?php
// Static GICS sector map for ~500 common US equities.
// Format: 'SYMBOL' => ['sector' => '...', 'industry' => '...']
// Falls back to AlphaVantage OVERVIEW for anything not listed here.

function getStaticSectorMap(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [
        // ── Information Technology ───────────────────────────────
        'AAPL'  => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'MSFT'  => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'NVDA'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'AVGO'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'ORCL'  => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'AMD'   => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'QCOM'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'TXN'   => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'INTC'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'AMAT'  => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'LRCX'  => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'KLAC'  => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'MU'    => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'ADI'   => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'MCHP'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'CDNS'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'SNPS'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'ANSS'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'CRM'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'ADBE'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'INTU'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'NOW'   => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'PANW'  => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'CSCO'  => ['sector'=>'Information Technology','industry'=>'Communications Equipment'],
        'IBM'   => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'ACN'   => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'CTSH'  => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'HPE'   => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'HPQ'   => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'DELL'  => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'NTAP'  => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'WDC'   => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'STX'   => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'ANET'  => ['sector'=>'Information Technology','industry'=>'Communications Equipment'],
        'GLW'   => ['sector'=>'Information Technology','industry'=>'Electronic Equipment'],
        'TEL'   => ['sector'=>'Information Technology','industry'=>'Electronic Components'],
        'APH'   => ['sector'=>'Information Technology','industry'=>'Electronic Components'],
        'KEYS'  => ['sector'=>'Information Technology','industry'=>'Electronic Equipment'],
        'FFIV'  => ['sector'=>'Information Technology','industry'=>'Communications Equipment'],
        'JNPR'  => ['sector'=>'Information Technology','industry'=>'Communications Equipment'],
        'ZBRA'  => ['sector'=>'Information Technology','industry'=>'Electronic Equipment'],
        'TDY'   => ['sector'=>'Information Technology','industry'=>'Electronic Equipment'],
        'EPAM'  => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'IT'    => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'GDDY'  => ['sector'=>'Information Technology','industry'=>'Internet Services'],
        'VRT'   => ['sector'=>'Information Technology','industry'=>'Electronic Equipment'],
        'SMCI'  => ['sector'=>'Information Technology','industry'=>'Technology Hardware'],
        'ARM'   => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'MRVL'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'ON'    => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'MPWR'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'NXPI'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'SWKS'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'QRVO'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'TER'   => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'ONTO'  => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'ENTG'  => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'MKSI'  => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'PLTR'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'SNOW'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'DDOG'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'CRWD'  => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'ZS'    => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'NET'   => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'FTNT'  => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'OKTA'  => ['sector'=>'Information Technology','industry'=>'Systems Software'],
        'MDB'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'WDAY'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'ADP'   => ['sector'=>'Information Technology','industry'=>'Data Processing Services'],
        'PAYX'  => ['sector'=>'Information Technology','industry'=>'Data Processing Services'],
        'FISV'  => ['sector'=>'Information Technology','industry'=>'Data Processing Services'],
        'FIS'   => ['sector'=>'Information Technology','industry'=>'Data Processing Services'],
        'GPN'   => ['sector'=>'Information Technology','industry'=>'Data Processing Services'],
        'VRSN'  => ['sector'=>'Information Technology','industry'=>'Internet Services'],
        'TYL'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'LDOS'  => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'SAIC'  => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'BAH'   => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'CACI'  => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'DXC'   => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'CDW'   => ['sector'=>'Information Technology','industry'=>'Technology Distributors'],
        'MSCI'  => ['sector'=>'Financials','industry'=>'Financial Exchanges'],

        // ── Communication Services ───────────────────────────────
        'GOOGL' => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'GOOG'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'META'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'NFLX'  => ['sector'=>'Communication Services','industry'=>'Movies & Entertainment'],
        'DIS'   => ['sector'=>'Communication Services','industry'=>'Movies & Entertainment'],
        'CMCSA' => ['sector'=>'Communication Services','industry'=>'Cable & Satellite'],
        'T'     => ['sector'=>'Communication Services','industry'=>'Integrated Telecom'],
        'VZ'    => ['sector'=>'Communication Services','industry'=>'Integrated Telecom'],
        'TMUS'  => ['sector'=>'Communication Services','industry'=>'Wireless Telecom'],
        'CHTR'  => ['sector'=>'Communication Services','industry'=>'Cable & Satellite'],
        'FOXA'  => ['sector'=>'Communication Services','industry'=>'Movies & Entertainment'],
        'FOX'   => ['sector'=>'Communication Services','industry'=>'Movies & Entertainment'],
        'WBD'   => ['sector'=>'Communication Services','industry'=>'Movies & Entertainment'],
        'PARA'  => ['sector'=>'Communication Services','industry'=>'Movies & Entertainment'],
        'IPG'   => ['sector'=>'Communication Services','industry'=>'Advertising'],
        'OMC'   => ['sector'=>'Communication Services','industry'=>'Advertising'],
        'MTCH'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'PINS'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'SNAP'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'LYV'   => ['sector'=>'Communication Services','industry'=>'Movies & Entertainment'],
        'NWSA'  => ['sector'=>'Communication Services','industry'=>'Publishing'],
        'NWS'   => ['sector'=>'Communication Services','industry'=>'Publishing'],
        'LBRDK' => ['sector'=>'Communication Services','industry'=>'Cable & Satellite'],
        'LBRDA' => ['sector'=>'Communication Services','industry'=>'Cable & Satellite'],
        'IAC'   => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'ZM'    => ['sector'=>'Communication Services','industry'=>'Application Software'],
        'TTWO'  => ['sector'=>'Communication Services','industry'=>'Interactive Home Entertainment'],
        'EA'    => ['sector'=>'Communication Services','industry'=>'Interactive Home Entertainment'],
        'RBLX'  => ['sector'=>'Communication Services','industry'=>'Interactive Home Entertainment'],
        'ATVI'  => ['sector'=>'Communication Services','industry'=>'Interactive Home Entertainment'],

        // ── Consumer Discretionary ──────────────────────────────
        'AMZN'  => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'TSLA'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'HD'    => ['sector'=>'Consumer Discretionary','industry'=>'Home Improvement Retail'],
        'MCD'   => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'NKE'   => ['sector'=>'Consumer Discretionary','industry'=>'Footwear'],
        'SBUX'  => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'LOW'   => ['sector'=>'Consumer Discretionary','industry'=>'Home Improvement Retail'],
        'TJX'   => ['sector'=>'Consumer Discretionary','industry'=>'Apparel Retail'],
        'BKNG'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'MAR'   => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'HLT'   => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'ORLY'  => ['sector'=>'Consumer Discretionary','industry'=>'Specialty Retail'],
        'AZO'   => ['sector'=>'Consumer Discretionary','industry'=>'Specialty Retail'],
        'ROST'  => ['sector'=>'Consumer Discretionary','industry'=>'Apparel Retail'],
        'YUM'   => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'CMG'   => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'DHI'   => ['sector'=>'Consumer Discretionary','industry'=>'Homebuilding'],
        'LEN'   => ['sector'=>'Consumer Discretionary','industry'=>'Homebuilding'],
        'PHM'   => ['sector'=>'Consumer Discretionary','industry'=>'Homebuilding'],
        'NVR'   => ['sector'=>'Consumer Discretionary','industry'=>'Homebuilding'],
        'TOL'   => ['sector'=>'Consumer Discretionary','industry'=>'Homebuilding'],
        'MDC'   => ['sector'=>'Consumer Discretionary','industry'=>'Homebuilding'],
        'GM'    => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'F'     => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'RIVN'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'LCID'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'CCL'   => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'RCL'   => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'NCLH'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'MGM'   => ['sector'=>'Consumer Discretionary','industry'=>'Casinos & Gaming'],
        'WYNN'  => ['sector'=>'Consumer Discretionary','industry'=>'Casinos & Gaming'],
        'LVS'   => ['sector'=>'Consumer Discretionary','industry'=>'Casinos & Gaming'],
        'CZR'   => ['sector'=>'Consumer Discretionary','industry'=>'Casinos & Gaming'],
        'EXPE'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'UBER'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'LYFT'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'ABNB'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'EBAY'  => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'ETSY'  => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'W'     => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'BBY'   => ['sector'=>'Consumer Discretionary','industry'=>'Computer & Electronics Retail'],
        'GPS'   => ['sector'=>'Consumer Discretionary','industry'=>'Apparel Retail'],
        'PVH'   => ['sector'=>'Consumer Discretionary','industry'=>'Apparel, Accessories & Luxury'],
        'RL'    => ['sector'=>'Consumer Discretionary','industry'=>'Apparel, Accessories & Luxury'],
        'TPR'   => ['sector'=>'Consumer Discretionary','industry'=>'Apparel, Accessories & Luxury'],
        'VFC'   => ['sector'=>'Consumer Discretionary','industry'=>'Apparel, Accessories & Luxury'],
        'HAS'   => ['sector'=>'Consumer Discretionary','industry'=>'Leisure Products'],
        'MAT'   => ['sector'=>'Consumer Discretionary','industry'=>'Leisure Products'],
        'POOL'  => ['sector'=>'Consumer Discretionary','industry'=>'Leisure Products'],
        'DRI'   => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'EAT'   => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'WING'  => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'QSR'   => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'DPZ'   => ['sector'=>'Consumer Discretionary','industry'=>'Restaurants'],
        'DKNG'  => ['sector'=>'Consumer Discretionary','industry'=>'Casinos & Gaming'],
        'PENN'  => ['sector'=>'Consumer Discretionary','industry'=>'Casinos & Gaming'],

        // ── Consumer Staples ─────────────────────────────────────
        'WMT'   => ['sector'=>'Consumer Staples','industry'=>'Consumer Staples Merchandise Retail'],
        'COST'  => ['sector'=>'Consumer Staples','industry'=>'Consumer Staples Merchandise Retail'],
        'TGT'   => ['sector'=>'Consumer Staples','industry'=>'Consumer Staples Merchandise Retail'],
        'KR'    => ['sector'=>'Consumer Staples','industry'=>'Food Retail'],
        'SYY'   => ['sector'=>'Consumer Staples','industry'=>'Food Distributors'],
        'PG'    => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'KO'    => ['sector'=>'Consumer Staples','industry'=>'Soft Drinks & Non-alcoholic Beverages'],
        'PEP'   => ['sector'=>'Consumer Staples','industry'=>'Soft Drinks & Non-alcoholic Beverages'],
        'PM'    => ['sector'=>'Consumer Staples','industry'=>'Tobacco'],
        'MO'    => ['sector'=>'Consumer Staples','industry'=>'Tobacco'],
        'BTI'   => ['sector'=>'Consumer Staples','industry'=>'Tobacco'],
        'MDLZ'  => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'CL'    => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'KMB'   => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'CHD'   => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'GIS'   => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'K'     => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'CPB'   => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'HRL'   => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'CAG'   => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'SJM'   => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'KHC'   => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'TSN'   => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],
        'TAP'   => ['sector'=>'Consumer Staples','industry'=>'Brewers'],
        'STZ'   => ['sector'=>'Consumer Staples','industry'=>'Distillers & Vintners'],
        'MNST'  => ['sector'=>'Consumer Staples','industry'=>'Soft Drinks & Non-alcoholic Beverages'],
        'CELH'  => ['sector'=>'Consumer Staples','industry'=>'Soft Drinks & Non-alcoholic Beverages'],
        'EL'    => ['sector'=>'Consumer Staples','industry'=>'Personal Care Products'],
        'COTY'  => ['sector'=>'Consumer Staples','industry'=>'Personal Care Products'],
        'REYN'  => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'CLX'   => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'SPB'   => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'UN'    => ['sector'=>'Consumer Staples','industry'=>'Personal Care Products'],
        'WBA'   => ['sector'=>'Consumer Staples','industry'=>'Drug Retail'],
        'CVS'   => ['sector'=>'Health Care','industry'=>'Health Care Services'],

        // ── Health Care ──────────────────────────────────────────
        'LLY'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'UNH'   => ['sector'=>'Health Care','industry'=>'Managed Health Care'],
        'JNJ'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'ABBV'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'MRK'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'TMO'   => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'ABT'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'DHR'   => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'BMY'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'AMGN'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'ISRG'  => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'PFE'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'MDT'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'SYK'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'BSX'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'BDX'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'EW'    => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'IDXX'  => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'IQV'   => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'RMD'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'DXCM'  => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'VRTX'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'REGN'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'BIIB'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'GILD'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'MRNA'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'ZBH'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'BAX'   => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'CAH'   => ['sector'=>'Health Care','industry'=>'Health Care Distributors'],
        'MCK'   => ['sector'=>'Health Care','industry'=>'Health Care Distributors'],
        'HUM'   => ['sector'=>'Health Care','industry'=>'Managed Health Care'],
        'CI'    => ['sector'=>'Health Care','industry'=>'Managed Health Care'],
        'CNC'   => ['sector'=>'Health Care','industry'=>'Managed Health Care'],
        'MOH'   => ['sector'=>'Health Care','industry'=>'Managed Health Care'],
        'HCA'   => ['sector'=>'Health Care','industry'=>'Health Care Facilities'],
        'THC'   => ['sector'=>'Health Care','industry'=>'Health Care Facilities'],
        'UHS'   => ['sector'=>'Health Care','industry'=>'Health Care Facilities'],
        'HOLX'  => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'AMED'  => ['sector'=>'Health Care','industry'=>'Health Care Services'],
        'ILMN'  => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'A'     => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'MLAB'  => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'MTD'   => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'PKI'   => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'WAT'   => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'TECH'  => ['sector'=>'Health Care','industry'=>'Life Sciences Tools & Services'],
        'PODD'  => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'INSP'  => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],
        'NTRA'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'RXRX'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'EXAS'  => ['sector'=>'Health Care','industry'=>'Health Care Equipment'],

        // ── Financials ───────────────────────────────────────────
        'BRK.B' => ['sector'=>'Financials','industry'=>'Multi-Sector Holdings'],
        'BRK.A' => ['sector'=>'Financials','industry'=>'Multi-Sector Holdings'],
        'JPM'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'BAC'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'WFC'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'GS'    => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'MS'    => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'BLK'   => ['sector'=>'Financials','industry'=>'Asset Management'],
        'C'     => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'USB'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'PNC'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'TFC'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'COF'   => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'AXP'   => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'DFS'   => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'SYF'   => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'MA'    => ['sector'=>'Financials','industry'=>'Transaction & Payment Processing'],
        'V'     => ['sector'=>'Financials','industry'=>'Transaction & Payment Processing'],
        'PYPL'  => ['sector'=>'Financials','industry'=>'Transaction & Payment Processing'],
        'ICE'   => ['sector'=>'Financials','industry'=>'Financial Exchanges'],
        'CME'   => ['sector'=>'Financials','industry'=>'Financial Exchanges'],
        'CBOE'  => ['sector'=>'Financials','industry'=>'Financial Exchanges'],
        'NDAQ'  => ['sector'=>'Financials','industry'=>'Financial Exchanges'],
        'SPGI'  => ['sector'=>'Financials','industry'=>'Financial Exchanges'],
        'MCO'   => ['sector'=>'Financials','industry'=>'Financial Exchanges'],
        'AON'   => ['sector'=>'Financials','industry'=>'Insurance Brokers'],
        'MMC'   => ['sector'=>'Financials','industry'=>'Insurance Brokers'],
        'WTW'   => ['sector'=>'Financials','industry'=>'Insurance Brokers'],
        'AIG'   => ['sector'=>'Financials','industry'=>'Multi-line Insurance'],
        'PRU'   => ['sector'=>'Financials','industry'=>'Life & Health Insurance'],
        'MET'   => ['sector'=>'Financials','industry'=>'Life & Health Insurance'],
        'AFL'   => ['sector'=>'Financials','industry'=>'Life & Health Insurance'],
        'ALL'   => ['sector'=>'Financials','industry'=>'Property & Casualty Insurance'],
        'PGR'   => ['sector'=>'Financials','industry'=>'Property & Casualty Insurance'],
        'TRV'   => ['sector'=>'Financials','industry'=>'Property & Casualty Insurance'],
        'CB'    => ['sector'=>'Financials','industry'=>'Property & Casualty Insurance'],
        'HIG'   => ['sector'=>'Financials','industry'=>'Property & Casualty Insurance'],
        'MTB'   => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'FITB'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'HBAN'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'RF'    => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'CFG'   => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'KEY'   => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'ZION'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'CMA'   => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'ALLY'  => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'SCHW'  => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'AMTD'  => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'IBKR'  => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'RJF'   => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'EVR'   => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'LAZ'   => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],
        'STT'   => ['sector'=>'Financials','industry'=>'Asset Management'],
        'BK'    => ['sector'=>'Financials','industry'=>'Asset Management'],
        'NTRS'  => ['sector'=>'Financials','industry'=>'Asset Management'],
        'TROW'  => ['sector'=>'Financials','industry'=>'Asset Management'],
        'IVZ'   => ['sector'=>'Financials','industry'=>'Asset Management'],
        'AMG'   => ['sector'=>'Financials','industry'=>'Asset Management'],
        'EG'    => ['sector'=>'Financials','industry'=>'Property & Casualty Insurance'],
        'FAF'   => ['sector'=>'Financials','industry'=>'Thrifts & Mortgage Finance'],
        'FNF'   => ['sector'=>'Financials','industry'=>'Thrifts & Mortgage Finance'],
        'SLM'   => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'OMF'   => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'COIN'  => ['sector'=>'Financials','industry'=>'Transaction & Payment Processing'],
        'NU'    => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'HOOD'  => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'],

        // ── Industrials ──────────────────────────────────────────
        'CAT'   => ['sector'=>'Industrials','industry'=>'Construction Machinery & Heavy Trucks'],
        'DE'    => ['sector'=>'Industrials','industry'=>'Agricultural & Farm Machinery'],
        'HON'   => ['sector'=>'Industrials','industry'=>'Industrial Conglomerates'],
        'GE'    => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'RTX'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'LMT'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'BA'    => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'NOC'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'GD'    => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'LHX'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'HII'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'TDG'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'AXON'  => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'LDOS'  => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'CARR'  => ['sector'=>'Industrials','industry'=>'Building Products'],
        'OTIS'  => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'EMR'   => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'ETN'   => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'PH'    => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'ITW'   => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'AME'   => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'FAST'  => ['sector'=>'Industrials','industry'=>'Industrial Distributors'],
        'GWW'   => ['sector'=>'Industrials','industry'=>'Industrial Distributors'],
        'ROK'   => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'SWK'   => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'ALLE'  => ['sector'=>'Industrials','industry'=>'Building Products'],
        'IR'    => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'IEX'   => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'XYL'   => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'FTV'   => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'NDSN'  => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'DOV'   => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'TXT'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'CTAS'  => ['sector'=>'Industrials','industry'=>'Diversified Support Services'],
        'RSG'   => ['sector'=>'Industrials','industry'=>'Environmental Services'],
        'WM'    => ['sector'=>'Industrials','industry'=>'Environmental Services'],
        'WCN'   => ['sector'=>'Industrials','industry'=>'Environmental Services'],
        'VRSK'  => ['sector'=>'Industrials','industry'=>'Research & Consulting Services'],
        'EXPD'  => ['sector'=>'Industrials','industry'=>'Air Freight & Logistics'],
        'CHRW'  => ['sector'=>'Industrials','industry'=>'Air Freight & Logistics'],
        'XPO'   => ['sector'=>'Industrials','industry'=>'Trucking'],
        'ODFL'  => ['sector'=>'Industrials','industry'=>'Trucking'],
        'JBHT'  => ['sector'=>'Industrials','industry'=>'Trucking'],
        'KNX'   => ['sector'=>'Industrials','industry'=>'Trucking'],
        'SAIA'  => ['sector'=>'Industrials','industry'=>'Trucking'],
        'UPS'   => ['sector'=>'Industrials','industry'=>'Air Freight & Logistics'],
        'FDX'   => ['sector'=>'Industrials','industry'=>'Air Freight & Logistics'],
        'DAL'   => ['sector'=>'Industrials','industry'=>'Airlines'],
        'UAL'   => ['sector'=>'Industrials','industry'=>'Airlines'],
        'AAL'   => ['sector'=>'Industrials','industry'=>'Airlines'],
        'LUV'   => ['sector'=>'Industrials','industry'=>'Airlines'],
        'ALK'   => ['sector'=>'Industrials','industry'=>'Airlines'],
        'JBLU'  => ['sector'=>'Industrials','industry'=>'Airlines'],
        'RCM'   => ['sector'=>'Industrials','industry'=>'Research & Consulting Services'],
        'PAYC'  => ['sector'=>'Industrials','industry'=>'Human Resource & Employment Services'],
        'G'     => ['sector'=>'Industrials','industry'=>'Research & Consulting Services'],
        'MAN'   => ['sector'=>'Industrials','industry'=>'Human Resource & Employment Services'],
        'URI'   => ['sector'=>'Industrials','industry'=>'Trading Companies & Distributors'],
        'AGCO'  => ['sector'=>'Industrials','industry'=>'Agricultural & Farm Machinery'],
        'PCAR'  => ['sector'=>'Industrials','industry'=>'Construction Machinery & Heavy Trucks'],
        'CMI'   => ['sector'=>'Industrials','industry'=>'Construction Machinery & Heavy Trucks'],
        'OSK'   => ['sector'=>'Industrials','industry'=>'Construction Machinery & Heavy Trucks'],
        'FLR'   => ['sector'=>'Industrials','industry'=>'Construction & Engineering'],
        'PWR'   => ['sector'=>'Industrials','industry'=>'Construction & Engineering'],
        'ACM'   => ['sector'=>'Industrials','industry'=>'Construction & Engineering'],
        'J'     => ['sector'=>'Industrials','industry'=>'Construction & Engineering'],
        'MMM'   => ['sector'=>'Industrials','industry'=>'Industrial Conglomerates'],
        'HOG'   => ['sector'=>'Consumer Discretionary','industry'=>'Motorcycle Manufacturers'],
        'GNRC'  => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'RRX'   => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'HUBB'  => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'ACCO'  => ['sector'=>'Industrials','industry'=>'Office Services & Supplies'],
        'ABM'   => ['sector'=>'Industrials','industry'=>'Diversified Support Services'],
        'GATX'  => ['sector'=>'Industrials','industry'=>'Railroads'],
        'NSC'   => ['sector'=>'Industrials','industry'=>'Railroads'],
        'CSX'   => ['sector'=>'Industrials','industry'=>'Railroads'],
        'UNP'   => ['sector'=>'Industrials','industry'=>'Railroads'],
        'WAB'   => ['sector'=>'Industrials','industry'=>'Construction Machinery & Heavy Trucks'],

        // ── Energy ───────────────────────────────────────────────
        'XOM'   => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'CVX'   => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'COP'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'EOG'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'SLB'   => ['sector'=>'Energy','industry'=>'Oil & Gas Equipment & Services'],
        'MPC'   => ['sector'=>'Energy','industry'=>'Oil & Gas Refining & Marketing'],
        'PSX'   => ['sector'=>'Energy','industry'=>'Oil & Gas Refining & Marketing'],
        'VLO'   => ['sector'=>'Energy','industry'=>'Oil & Gas Refining & Marketing'],
        'OXY'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'DVN'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'HES'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'FANG'  => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'MRO'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'APA'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'HAL'   => ['sector'=>'Energy','industry'=>'Oil & Gas Equipment & Services'],
        'BKR'   => ['sector'=>'Energy','industry'=>'Oil & Gas Equipment & Services'],
        'NOV'   => ['sector'=>'Energy','industry'=>'Oil & Gas Equipment & Services'],
        'LNG'   => ['sector'=>'Energy','industry'=>'Oil & Gas Storage & Transportation'],
        'KMI'   => ['sector'=>'Energy','industry'=>'Oil & Gas Storage & Transportation'],
        'OKE'   => ['sector'=>'Energy','industry'=>'Oil & Gas Storage & Transportation'],
        'WMB'   => ['sector'=>'Energy','industry'=>'Oil & Gas Storage & Transportation'],
        'ET'    => ['sector'=>'Energy','industry'=>'Oil & Gas Storage & Transportation'],
        'TRGP'  => ['sector'=>'Energy','industry'=>'Oil & Gas Storage & Transportation'],
        'EQT'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'CNX'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'CHK'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'RRC'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'AR'    => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'CIVI'  => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'PR'    => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],

        // ── Materials ────────────────────────────────────────────
        'LIN'   => ['sector'=>'Materials','industry'=>'Industrial Gases'],
        'APD'   => ['sector'=>'Materials','industry'=>'Industrial Gases'],
        'ECL'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'SHW'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'PPG'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'NEM'   => ['sector'=>'Materials','industry'=>'Gold'],
        'FCX'   => ['sector'=>'Materials','industry'=>'Copper'],
        'NUE'   => ['sector'=>'Materials','industry'=>'Steel'],
        'STLD'  => ['sector'=>'Materials','industry'=>'Steel'],
        'RS'    => ['sector'=>'Materials','industry'=>'Metal & Glass Containers'],
        'VMC'   => ['sector'=>'Materials','industry'=>'Construction Materials'],
        'MLM'   => ['sector'=>'Materials','industry'=>'Construction Materials'],
        'ALB'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'RPM'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'AVY'   => ['sector'=>'Materials','industry'=>'Paper & Plastic Packaging'],
        'SEE'   => ['sector'=>'Materials','industry'=>'Paper & Plastic Packaging'],
        'PKG'   => ['sector'=>'Materials','industry'=>'Paper & Plastic Packaging'],
        'IP'    => ['sector'=>'Materials','industry'=>'Paper & Plastic Packaging'],
        'WRK'   => ['sector'=>'Materials','industry'=>'Paper & Plastic Packaging'],
        'CF'    => ['sector'=>'Materials','industry'=>'Fertilizers & Agricultural Chemicals'],
        'MOS'   => ['sector'=>'Materials','industry'=>'Fertilizers & Agricultural Chemicals'],
        'FMC'   => ['sector'=>'Materials','industry'=>'Fertilizers & Agricultural Chemicals'],
        'IFF'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'EMN'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'CE'    => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'DOW'   => ['sector'=>'Materials','industry'=>'Commodity Chemicals'],
        'LYB'   => ['sector'=>'Materials','industry'=>'Commodity Chemicals'],
        'HUN'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'ASH'   => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],
        'ATI'   => ['sector'=>'Materials','industry'=>'Steel'],
        'TREX'  => ['sector'=>'Materials','industry'=>'Building Products'],
        'X'     => ['sector'=>'Materials','industry'=>'Steel'],
        'CLF'   => ['sector'=>'Materials','industry'=>'Steel'],

        // ── Utilities ────────────────────────────────────────────
        'NEE'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'DUK'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'SO'    => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'D'     => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'PCG'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'EXC'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'AEP'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'XEL'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'WEC'   => ['sector'=>'Utilities','industry'=>'Multi-Utilities'],
        'ES'    => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'DTE'   => ['sector'=>'Utilities','industry'=>'Multi-Utilities'],
        'PPL'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'ETR'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'EIX'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'CMS'   => ['sector'=>'Utilities','industry'=>'Multi-Utilities'],
        'AES'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'LNT'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'EVRG'  => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'PNW'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'NI'    => ['sector'=>'Utilities','industry'=>'Gas Utilities'],
        'AWK'   => ['sector'=>'Utilities','industry'=>'Water Utilities'],
        'SRE'   => ['sector'=>'Utilities','industry'=>'Multi-Utilities'],
        'CNP'   => ['sector'=>'Utilities','industry'=>'Multi-Utilities'],
        'NRG'   => ['sector'=>'Utilities','industry'=>'Independent Power Producers'],
        'VST'   => ['sector'=>'Utilities','industry'=>'Independent Power Producers'],
        'CEG'   => ['sector'=>'Utilities','industry'=>'Independent Power Producers'],
        'OGE'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'POR'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],
        'AEE'   => ['sector'=>'Utilities','industry'=>'Multi-Utilities'],
        'FE'    => ['sector'=>'Utilities','industry'=>'Electric Utilities'],

        // ── Real Estate ──────────────────────────────────────────
        'PLD'   => ['sector'=>'Real Estate','industry'=>'Industrial REITs'],
        'AMT'   => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'EQIX'  => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'CCI'   => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'SPG'   => ['sector'=>'Real Estate','industry'=>'Retail REITs'],
        'O'     => ['sector'=>'Real Estate','industry'=>'Retail REITs'],
        'PSA'   => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'EQR'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'AVB'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'ESS'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'MAA'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'UDR'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'CPT'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'IRM'   => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'SBAC'  => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'DLR'   => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'VTR'   => ['sector'=>'Real Estate','industry'=>'Health Care REITs'],
        'WELL'  => ['sector'=>'Real Estate','industry'=>'Health Care REITs'],
        'HR'    => ['sector'=>'Real Estate','industry'=>'Health Care REITs'],
        'PEAK'  => ['sector'=>'Real Estate','industry'=>'Health Care REITs'],
        'SLG'   => ['sector'=>'Real Estate','industry'=>'Office REITs'],
        'BXP'   => ['sector'=>'Real Estate','industry'=>'Office REITs'],
        'HIW'   => ['sector'=>'Real Estate','industry'=>'Office REITs'],
        'KIM'   => ['sector'=>'Real Estate','industry'=>'Retail REITs'],
        'REG'   => ['sector'=>'Real Estate','industry'=>'Retail REITs'],
        'FRT'   => ['sector'=>'Real Estate','industry'=>'Retail REITs'],
        'NNN'   => ['sector'=>'Real Estate','industry'=>'Retail REITs'],
        'STAG'  => ['sector'=>'Real Estate','industry'=>'Industrial REITs'],
        'REXR'  => ['sector'=>'Real Estate','industry'=>'Industrial REITs'],
        'ELS'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'SUI'   => ['sector'=>'Real Estate','industry'=>'Residential REITs'],
        'CUBE'  => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'EXR'   => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'GLPI'  => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'VICI'  => ['sector'=>'Real Estate','industry'=>'Specialized REITs'],
        'MPW'   => ['sector'=>'Real Estate','industry'=>'Health Care REITs'],
        'CBRE'  => ['sector'=>'Real Estate','industry'=>'Real Estate Services'],
        'JLL'   => ['sector'=>'Real Estate','industry'=>'Real Estate Services'],
        'CIGI'  => ['sector'=>'Real Estate','industry'=>'Real Estate Services'],

        // ── Common ADRs & International ──────────────────────────
        'TSM'   => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        '2330'  => ['sector'=>'Information Technology','industry'=>'Semiconductors'],
        'ASML'  => ['sector'=>'Information Technology','industry'=>'Semiconductor Equipment'],
        'NVO'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'SHEL'  => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'TTE'   => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'BP'    => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'RIO'   => ['sector'=>'Materials','industry'=>'Diversified Metals & Mining'],
        'BHP'   => ['sector'=>'Materials','industry'=>'Diversified Metals & Mining'],
        'VALE'  => ['sector'=>'Materials','industry'=>'Steel'],
        'SAP'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'SONY'  => ['sector'=>'Consumer Discretionary','industry'=>'Consumer Electronics'],
        'TM'    => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'HMC'   => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'RACE'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'UL'    => ['sector'=>'Consumer Staples','industry'=>'Personal Care Products'],
        'NVS'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'AZN'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'GSK'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'SNY'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],
        'INFY'  => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'WIT'   => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'],
        'ABB'   => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'SIEGY' => ['sector'=>'Industrials','industry'=>'Industrial Conglomerates'],
        'HSBC'  => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'ING'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'DB'    => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'BCS'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'BABA'  => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'JD'    => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'PDD'   => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'SE'    => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],
        'MELI'  => ['sector'=>'Consumer Discretionary','industry'=>'Broadline Retail'],

        // ── Ticker variants & aliases ────────────────────────────
        'BRK-B' => ['sector'=>'Financials','industry'=>'Multi-Sector Holdings'],   // BRK.B hyphen form
        'BRK/B' => ['sector'=>'Financials','industry'=>'Multi-Sector Holdings'],
        'BRK-A' => ['sector'=>'Financials','industry'=>'Multi-Sector Holdings'],
        'BRK/A' => ['sector'=>'Financials','industry'=>'Multi-Sector Holdings'],

        // ── US stocks missing from initial list ──────────────────
        'AVAV'  => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],
        'GEV'   => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'JOBY'  => ['sector'=>'Industrials','industry'=>'Airlines'],
        'ROP'   => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],
        'BX'    => ['sector'=>'Financials','industry'=>'Asset Management'],
        'KKR'   => ['sector'=>'Financials','industry'=>'Asset Management'],
        'APO'   => ['sector'=>'Financials','industry'=>'Asset Management'],
        'AJG'   => ['sector'=>'Financials','industry'=>'Insurance Brokers'],
        'SHOP'  => ['sector'=>'Information Technology','industry'=>'Internet Services'],
        'ADSK'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'HUBS'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'VEEV'  => ['sector'=>'Health Care','industry'=>'Application Software'],
        'ZI'    => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'BILL'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'TTD'   => ['sector'=>'Information Technology','industry'=>'Internet Services'],
        'DUOL'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'DASH'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'ABNB'  => ['sector'=>'Consumer Discretionary','industry'=>'Hotels & Travel'],
        'SQ'    => ['sector'=>'Financials','industry'=>'Transaction & Payment Processing'],
        'AFRM'  => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'SOFI'  => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'UPST'  => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'LC'    => ['sector'=>'Financials','industry'=>'Consumer Finance'],
        'NTRA'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'SRPT'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'RARE'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'INSM'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'ACAD'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'ROIV'  => ['sector'=>'Health Care','industry'=>'Biotechnology'],
        'SU'    => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'ENR'   => ['sector'=>'Consumer Staples','industry'=>'Household Products'],
        'GNRC'  => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'],
        'TOST'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'APP'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'CFLT'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'PATH'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'AI'    => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'GTLB'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'IOT'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'ESTC'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'SMAR'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'PCOR'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'BSY'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'PTC'   => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'MANH'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'ALTR'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'NCNO'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'RY'    => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'TD'    => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'BMO'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'BNS'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'CM'    => ['sector'=>'Financials','industry'=>'Diversified Banks'],
        'MFC'   => ['sector'=>'Financials','industry'=>'Life & Health Insurance'],
        'SLF'   => ['sector'=>'Financials','industry'=>'Life & Health Insurance'],
        'CNQ'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'],
        'CVE'   => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'IMO'   => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],
        'CNR'   => ['sector'=>'Industrials','industry'=>'Railroads'],
        'CP'    => ['sector'=>'Industrials','industry'=>'Railroads'],
        'WCN'   => ['sector'=>'Industrials','industry'=>'Environmental Services'],
        'SAP'   => ['sector'=>'Information Technology','industry'=>'Application Software'],

        // ── Foreign exchange tickers (non-US listings) ───────────
        '005930' => ['sector'=>'Information Technology','industry'=>'Semiconductors'],   // Samsung KRX
        '000660' => ['sector'=>'Information Technology','industry'=>'Semiconductors'],   // SK Hynix KRX
        'NOVN'  => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],              // Novartis CHF
        'NESN'  => ['sector'=>'Consumer Staples','industry'=>'Packaged Foods & Meats'],  // Nestlé CHF
        'ABBN'  => ['sector'=>'Industrials','industry'=>'Electrical Components & Equipment'], // ABB CHF
        'ZURN'  => ['sector'=>'Financials','industry'=>'Property & Casualty Insurance'], // Zurich Insurance
        'CSGN'  => ['sector'=>'Financials','industry'=>'Diversified Banks'],             // Credit Suisse CHF
        'UBSG'  => ['sector'=>'Financials','industry'=>'Diversified Banks'],             // UBS CHF
        'SIE'   => ['sector'=>'Industrials','industry'=>'Industrial Conglomerates'],     // Siemens DE
        'ALV'   => ['sector'=>'Financials','industry'=>'Multi-line Insurance'],          // Allianz DE
        'MUV2'  => ['sector'=>'Financials','industry'=>'Multi-line Insurance'],          // Munich Re DE
        'ADS'   => ['sector'=>'Consumer Discretionary','industry'=>'Footwear'],          // Adidas DE
        'BAS'   => ['sector'=>'Materials','industry'=>'Commodity Chemicals'],            // BASF DE
        'BAYN'  => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],              // Bayer DE
        'VOW3'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'], // VW DE
        'BMW'   => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'], // BMW DE
        'MBG'   => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'], // Mercedes DE
        'SAP'   => ['sector'=>'Information Technology','industry'=>'Application Software'],     // SAP DE (same US)
        'MC'    => ['sector'=>'Consumer Discretionary','industry'=>'Apparel, Accessories & Luxury'], // LVMH FR
        'OR'    => ['sector'=>'Consumer Staples','industry'=>'Personal Care Products'],  // L'Oréal FR
        'SAN'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],             // Santander ES
        'IBE'   => ['sector'=>'Utilities','industry'=>'Electric Utilities'],             // Iberdrola ES
        'ITX'   => ['sector'=>'Consumer Discretionary','industry'=>'Apparel Retail'],    // Inditex ES
        'HSBA'  => ['sector'=>'Financials','industry'=>'Diversified Banks'],             // HSBC UK
        'SHEL'  => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],             // Shell UK (already added)
        'AZN'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],             // AstraZeneca UK (already added)
        'GSK'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],             // GSK UK (already added)
        'ULVR'  => ['sector'=>'Consumer Staples','industry'=>'Personal Care Products'], // Unilever UK
        'RR.'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],         // Rolls-Royce UK
        'CBA'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // Commonwealth Bank AU
        'BHP'   => ['sector'=>'Materials','industry'=>'Diversified Metals & Mining'],   // BHP AU (already added)
        'CSL'   => ['sector'=>'Health Care','industry'=>'Biotechnology'],               // CSL AU
        '7203'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'], // Toyota JP
        '6758'  => ['sector'=>'Consumer Discretionary','industry'=>'Consumer Electronics'],     // Sony JP
        '9984'  => ['sector'=>'Communication Services','industry'=>'Wireless Telecom'],         // SoftBank JP
        '6501'  => ['sector'=>'Industrials','industry'=>'Industrial Conglomerates'],            // Hitachi JP
        'NOVO B'=> ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],             // Novo Nordisk DK
        'NOVOB' => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],             // Novo Nordisk DK (no space)

        // ── More US stocks (common ETF constituents) ─────────────
        'TT'    => ['sector'=>'Industrials','industry'=>'Industrial Machinery'],        // Trane Technologies
        'ELV'   => ['sector'=>'Health Care','industry'=>'Managed Health Care'],         // Elevance Health (fka Anthem)
        'AMP'   => ['sector'=>'Financials','industry'=>'Asset Management'],             // Ameriprise Financial
        'TPL'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'], // Texas Pacific Land
        'CTRA'  => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'], // Coterra Energy
        'FTI'   => ['sector'=>'Energy','industry'=>'Oil & Gas Equipment & Services'],   // TechnipFMC
        'XYZ'   => ['sector'=>'Financials','industry'=>'Transaction & Payment Processing'], // Block Inc (fka SQ)
        'BN'    => ['sector'=>'Financials','industry'=>'Asset Management'],             // Brookfield Corp
        'BAM'   => ['sector'=>'Financials','industry'=>'Asset Management'],             // Brookfield Asset Mgmt
        'EXE'   => ['sector'=>'Energy','industry'=>'Oil & Gas Exploration & Production'], // Expand Energy
        'SPOT'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'PINS'  => ['sector'=>'Communication Services','industry'=>'Interactive Media'],
        'RIVN'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'LCID'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'NIO'   => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'LI'    => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'XPEV'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'],
        'CELH'  => ['sector'=>'Consumer Staples','industry'=>'Soft Drinks & Non-alcoholic Beverages'],
        'CBOE'  => ['sector'=>'Financials','industry'=>'Financial Exchanges'],
        'DT'    => ['sector'=>'Information Technology','industry'=>'Application Software'],  // Dynatrace
        'PAYC'  => ['sector'=>'Information Technology','industry'=>'Application Software'],
        'RKT'   => ['sector'=>'Financials','industry'=>'Thrifts & Mortgage Finance'],
        'NYCB'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'WAL'   => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'WTFC'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'COLB'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'PNFP'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'BOKF'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'IBOC'  => ['sector'=>'Financials','industry'=>'Regional Banks'],
        'CFR'   => ['sector'=>'Financials','industry'=>'Regional Banks'],                // Cullen/Frost Bankers
        'ENB'   => ['sector'=>'Energy','industry'=>'Oil & Gas Storage & Transportation'], // Enbridge

        // ── More foreign tickers (common in international ETFs) ──
        'AIR'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],         // Airbus FR
        'SAF'   => ['sector'=>'Industrials','industry'=>'Aerospace & Defense'],         // Safran FR
        'BNP'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // BNP Paribas FR
        'ACA'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // Crédit Agricole FR
        'GLE'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // Société Générale FR
        'CS'    => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // AXA FR (also used for Credit Suisse)
        'BBVA'  => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // BBVA ES
        'UCG'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // UniCredit IT
        'ISP'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // Intesa Sanpaolo IT
        'G'     => ['sector'=>'Financials','industry'=>'Multi-line Insurance'],         // Generali IT
        'RACE'  => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'], // Ferrari IT
        'BP.'   => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],             // BP UK (with period)
        'BATS'  => ['sector'=>'Consumer Staples','industry'=>'Tobacco'],                // British American Tobacco UK
        'GSK'   => ['sector'=>'Health Care','industry'=>'Pharmaceuticals'],             // GSK UK
        'WBC'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // Westpac AU
        'ANZ'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // ANZ Bank AU
        'NAB'   => ['sector'=>'Financials','industry'=>'Diversified Banks'],            // National Australia Bank
        'MQG'   => ['sector'=>'Financials','industry'=>'Investment Banking & Brokerage'], // Macquarie AU
        'WES'   => ['sector'=>'Consumer Staples','industry'=>'Consumer Staples Merchandise Retail'], // Wesfarmers AU
        'WOW'   => ['sector'=>'Consumer Staples','industry'=>'Food Retail'],            // Woolworths AU
        'RELIANCE' => ['sector'=>'Energy','industry'=>'Integrated Oil & Gas'],          // Reliance Industries IN
        'TCS'   => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'], // Tata Consultancy IN
        'INFY'  => ['sector'=>'Information Technology','industry'=>'IT Consulting & Services'], // Infosys IN (also US)
        '005380' => ['sector'=>'Consumer Discretionary','industry'=>'Automobile Manufacturers'], // Hyundai KR
        '051910' => ['sector'=>'Materials','industry'=>'Specialty Chemicals'],          // LG Chem KR
        '035420' => ['sector'=>'Communication Services','industry'=>'Interactive Media'], // Naver KR
        '035720' => ['sector'=>'Communication Services','industry'=>'Interactive Media'], // Kakao KR
    ];
    return $map;
}

/** Look up sector for a symbol. Returns null if not in static map. */
function getStaticSector(string $symbol): ?array {
    $map = getStaticSectorMap();
    return $map[strtoupper(trim($symbol))] ?? null;
}

/**
 * Classify a symbol as a non-equity instrument, or return null if it looks
 * like a real stock that should get a GICS sector lookup.
 *
 * Returns one of: 'Cash & Equivalents' | 'Fixed Income' | 'Crypto' | null
 * A non-null return means skip GICS classification and use this pseudo-sector.
 * Also returns the special sentinel '__filter__' for garbage entries that should
 * be excluded from the sector report entirely.
 */
function classifyNonEquity(string $rawSymbol): ?string {
    $sym = strtoupper(trim($rawSymbol));

    // ── Garbage / invalid tickers ────────────────────────────────
    // Single characters, pure-numeric short strings (0-99), or empty
    if ($sym === '' || preg_match('/^\d{1,2}$/', $sym)) return '__filter__';

    // ── Long-name instruments (mutual funds, 529 plans, etc.) ────
    // Real tickers never contain spaces; long names are fund instruments
    if (str_contains($rawSymbol, ' ') && strlen($rawSymbol) > 10) return 'Other Funds';

    // ── Broad/diversified ETFs used as fund constituents ─────────
    // These appear in the map when one ETF holds another ETF.
    // Classifying them by sector is meaningless — mark as Diversified ETF.
    static $broadEtfs = [
        'SPY','IVV','VOO','VTI','QQQ','VT','ITOT','SCHB','SPTM','IWB','IWV',
        'SCHX','VXF','IJR','IWM','VTWO','SPSM','MDY','IJH','IVOO','VOOG','VOOV',
        'EFA','IEFA','VEA','SCHF','EEM','VWO','IEMG','ACWI','ACWX','VEU',
        'AGG','BND','BNDW','TLT','IEF','SHY','LQD','HYG','JNK','VCIT','VCLT',
        'GLD','IAU','SLV','GSG','DJP','PDBC',
    ];
    if (in_array($sym, $broadEtfs)) return 'Diversified ETF';

    // ── Crypto ───────────────────────────────────────────────────
    if (str_ends_with($sym, '-USD') || str_ends_with($sym, '-BTC') ||
        in_array($sym, ['BTC','ETH','SOL','XRP','ADA','DOGE','AVAX','MATIC','DOT','LINK',
                         'LTC','UNI','XLM','ATOM','ALGO','VET','ICP','FIL','HBAR','SAND'])) {
        return 'Crypto';
    }

    // ── Money market funds ───────────────────────────────────────
    // Fidelity/Vanguard/Schwab money markets typically end in XX or XXX
    if (preg_match('/XX+$/', $sym) ||
        in_array($sym, ['SPAXX','FZFXX','FDRXX','FZFXX','FDLXX','FTEXX','FCASH',
                         'TMCXX','IIAXX','SWVXX','SNOXX','SNVXX','VMMXX','VMRXX',
                         'VMFXX','VMSXX','VCTXX','VYFXX','GVMXX'])) {
        return 'Cash & Equivalents';
    }

    // ── Fixed income indicators ──────────────────────────────────
    // CUSIP-like: 9-char alphanumeric (numbers + letters, starts with digit or letter)
    if (preg_match('/^[A-Z0-9]{9}$/', $sym) && preg_match('/\d/', $sym) && preg_match('/[A-Z]/', $sym)) {
        return 'Fixed Income';
    }
    // Long descriptive names containing bond/treasury/CD keywords
    $lower = strtolower($rawSymbol);
    if (str_contains($lower, 'treasury') || str_contains($lower, ' bond') ||
        str_contains($lower, 'cd ') || str_contains($lower, ' cd') ||
        str_contains($lower, 'deposit') || str_contains($lower, 'certificate') ||
        str_contains($lower, 'note ') || str_contains($lower, 'corp bond')) {
        return 'Fixed Income';
    }

    return null; // looks like a real equity — proceed with GICS lookup
}
