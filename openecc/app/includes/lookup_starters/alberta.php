<?php
// Alberta starter list for Settings > Lookups (includes/lookups.php). Loaded only when a SysAdmin chooses it.
// Organizations that commonly take part in Alberta municipal emergencies. Names were checked in November 2026;
// provincial ministries are left out because their names change with cabinet shuffles. Review it twice a year with
// the ICS Canada forms check, and edit or disable entries on the Lookups page to suit your municipality.
return [
    'label' => 'Alberta',
    'checked' => 'November 2026',
    'lists' => [
        'Agencies' => [
            ['Alberta Emergency Management Agency (AEMA)', 'Provincial emergency coordination; field officers; Provincial Emergency Coordination Centre'],
            ['Emergency Health Services - Alberta (EHS-AB)', 'Ground ambulance (EMS)'],
            ['Alberta Health Services (AHS)', 'Hospitals'],
            ['STARS Air Ambulance', 'Air ambulance'],
            ['RCMP - K Division', 'Police (Alberta)'],
            ['Alberta Sheriffs', 'Provincial law enforcement'],
            ['Alberta Wildfire', 'Wildfire in the Forest Protection Area'],
            ['Alberta Energy Regulator (AER)', 'Oil and gas, pipelines'],
            ['Environment and Climate Change Canada', 'Weather warnings'],
            ['Indigenous Services Canada', 'Emergency support for First Nations'],
            ['Canadian Armed Forces', 'Military assistance when requested through the province'],
            ['Canadian Red Cross', 'Evacuee registration, reception and lodging support'],
            ['The Salvation Army', 'Food, clothing and emotional support'],
            ['St. John Ambulance', 'First aid'],
            ['Samaritan\'s Purse', 'Disaster relief and clean-up'],
            ['Mennonite Disaster Service', 'Clean-up and rebuilding'],
            ['ATCO', 'Electricity and natural gas utility'],
            ['FortisAlberta', 'Electricity distribution'],
            ['ENMAX', 'Electricity utility'],
            ['EPCOR', 'Water and electricity utility'],
            ['TELUS', 'Telecommunications'],
            ['Utility Safety Partners', 'Underground utility locates (Click Before You Dig)'],
            ['CN', 'Railway'],
            ['CPKC', 'Railway'],
        ],
    ],
];
