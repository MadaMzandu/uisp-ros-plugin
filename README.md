# UISP/UCRM Plugin for RouterOs Services

So it finally came to be, a simple to install plugin replacement for the previous api based version.

# Installation

1. Download the zip file in the src directory and upload into your Uisp > Settings > Plugins.

2. Enable the plugin and create the webhook.

# Configuration

1. After enabling the plugin a menu icon will be installed for the plugin. Click on the icon and go to panel.
2. In the devices tab add your mikrotik devices
3. In the plans tab set the contention ratios for your plans or leave as 1:1 if not selling contention. If no plans are defined yet, define your plans in Uisp > Settings > Service Plans & Products
4. In the settings tab go to the attributes tab and define the attributes that you want to use. You will need device name and mac address for dhcp, pppoe username,password and device name for pppoe. You can also set an ip address attribute if you wish to manually assign ip addresses for some accounts. You can also enable all the attributes if you are using both pppoe and dhcp.
5. If using pppoe, in the Settings > General tab select if you want to use one pool for all your routers and specify the pool, or you want to use per router pool specified in the device.
6. Thats it your are done.

# Upgrading
If you have been using the previous api based version:

1. After installation and configuration above disable the webhook for the previous api based version.
2. Go to the settings tab > system and click rebuild to populate with accounts from previous api version.

Please ensure that your mikrotik devices are added to the plugin and are online. The rebuild process is harmless and can be run any number of times.

# Using
The custom attributes in Configution (4) should be listed in the form when creating or editing a service.
1. Fill the device name to specify the router for the clients account
2. PPPoE username and Password to provision PPPoE.
3. Mac address to provision DHCP instead.
4. IP address to bypass the pool





