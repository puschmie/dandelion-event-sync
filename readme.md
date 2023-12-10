# Dandelion Event Sync
This plugin is made to synchronize events from your dandelion  account once a day or with the click of a button

## Setup
To get started you need to install Advanced Custom Fields (ACF) and import the acf.json file from this repository. It will create all of the custom post types, taxonomies and fields required for this plugin to function properly. 

After you've setup ACF all you have to do is enter the slug of your organization in the "Event Sync" Dashboard (find it in your main admin menu bar).

## Functionality
The ACF import will create two post types (events and projects), a field group for the event post type and a "topics" taxonomy.
When syncing with Dandelion the plugin will create seperate "event" posts per event. The point of the "projects" post type is to be able to sort events with higher granularity. You can create projects if you like to use this feature or ignore it if you don't need more granularity. If you use it, make sure to include the exact name of the project you would like to link an event to as a tag when creating the event on Dandelion. The plugin wil link each event to the first match for a project it finds among the tags. The rest of the tags will be added as terms in the "topics" taxonomy. 

### Creating & updating events
Create your event on Dandelion as you would normally - make sure to include a banner image! Then head to your Event Sync Dashboard and hit the "Sync now" button. The event will be imported, including the featured image and an embedded registration form through which users can buy tickets directly via Dandelion. The layout of your event description will be preserved. To update events edit them on dandelion as you would and then hit the manual snyc button again. The plugin will automatically sync once a day so any edits you make to events on your wordpress will be lost tomorrow.


## Displaying Events
Using the Wordpress Block Editor you can add a "Query Loop" Block on any page, choose the "Events" post type in the configuration and enjoy your freshly imported events. There are many ways of customizing this with block plugins that enable you to add filtering capabilities to the front end and provide more style customization options.