# UISP/UCRM Plugin for RouterOs Services

So it finally came to be, a simple to install plugin replacement for the previous api based version.

# New Features

1. All new graphical interface for adding devices and managing settings
2. One click installation via uisp plugin interface
3. Contention ratios - the plugin will now create and manage parent queues to apply contention per router device.
4. Automatic ppp profile creation - ppp profiles for service plans are now created/removed automatically
5. Automatic ppp username and password generation
6. Rebuild utility - the plugin can now re-sync itself and router devices against uisp. A new router can now be repopulated with accounts in minutes.
7. Automatic backup - although the plugin can resync itself with uisp and managed routers, there is a now backup feature for added redundancy.

## Other features
1. Real time provisioning, editing, suspending, unsuspending of accounts
2. Real time migration of accounts between devices
3. Supports both dhcp and pppoe and other ppp variants

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

Please ensure that your mikrotik devices are added to the plugin and are online. The rebuild process is harmless and can be run any number of times. To verify if rebuild was successful go to the Devices tab and check the number of user accounts listed for each device.

# Using

The custom attributes in Configution (4) should be listed in the form when creating or editing a service in Uisp.
1. Fill the device name to specify the router for the clients account (see making a device dropdown list below)
2. PPPoE username and Password to provision PPPoE.
3. Mac address to provision DHCP instead.
4. IP address to bypass the pool

## Enabling automatic username and password generation

1. You need to go to panel > settings > general and enable the checkbox for this feature.
2. When adding a service for a client leave the username and password blank to let plugin generate the field automatically, thats it.

Also note the following;

1. A manually typed in username or password will override automatic generation for the field.
2. Deleting the existing username or password will cause the plugin to generate a new value for the field
3. The username is either client login, client lastname or client company name with service number appended.

## Making a dropdown device list

1. Go to crm settings > other > custom attributes.
2. Create a new custom attribute with the following parameters - type: choice, Attribute type: service,Client Visible:no.
3. Add the device names of your managed mikrotiks as values. You have to manually update this list of values when you add a new device.
4. Next go to plugin panel > settings > attributes and in the device name field type in the name of the attribute created in step 2. 
5. Click the save button when the panel finds the attribute.

There are two reasons why this can only be done manually if you are interested to know: 

1. Plugins can query crm but crm cannot query plugins. And since custom attrbutes are part of crm it means they cannot pull values such as device names from the plugin.
2. Why not push the list of devices to crm then? True this would be semi static fallback solution but crm API has not provided a call that can push values to an enumerated custom attribute so for now this will have to do.

## Handling account suspension and unsuspension

The plugin provides four mikrotik parameters that allow customizing how disabled accounts are handled. These are:
1. required : disabled profile - the name of a ppp profile to assign suspended accounts. Created automatically if missing.
2. required : disabled address list - the name of firewall address list to assign suspended accounts. Firewall rules for the the list must be manually configured by admin. The rules can be nat rules to redirect and filter rules to drop.
3. required : disabled rate - the rate limit to apply to disabled accounts. Automatically applied to ppp profile or dhcp queue. 
4. optional : active address list - extra address list to apply to active accounts. Firewall rules must be manually configured by admin.

# Credits

This software uses or depends on the following software by these developers with
the greatest gratitude.

Ben Menking – RouterOS API

<https://github.com/BenMenking/routeros-api>

Ubiquiti - UISP/UCRM/UNMS

<https://ubnt.com>

Mikrotik - RouterOS

<https://mikrotik.com>




