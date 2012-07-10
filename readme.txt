Converting Database Character Set

I've tried converting a MODx database from latin1 to utf8 several times over the last two years, with no luck.

I finally wrote my own script to do it. The script in this package should convert from any charset/collation
to any other charset/collation. By default it converts the current charset/collation to utf8/utf3_general_ci.

Full disclosure: I consider character sets to be a black art, so I can't claim I fully understand this process.
I'm not sure all the steps are necessary, but the method worked for me. YMMV.

This method assumes that you will be converting a local copy of a remote database.

1. Using PhpMyAdmin on the remote site, export the db to a local file using the default settings. Call the
   file [sitename-]remote-latin1.sql. Note that this file will serve as a backup of the remote DB in case anything
   goes wrong. Be careful not to overwrite or alter it!

2. Using PhpMyAdmin on the local site, create an empty latin1 database called [sitename]temp.
   Use no hyphens in the DB name.

3. Using PhpMyAdmin's Import tab, import the remote-latin1.sql file into the newly created database. Be sure to set
   the files charset to Latin1 (if that's what your old DB is) before importing. If you have mixed Latin1 and UTF8
   tables or fields in your DB, try using the charset of the old DB. If you don't like the results, try the whole
   process again with the other charset and see which gives you better results.

4. Export the newly created database to an sql file called before.sql. This is necessary because you may be comparing
   this file with the converted DB and it works best if they were both exported by the same version of PhpMyAdmin.

5. Edit the variables at the beginning of the cdc.php file to match your database and desired charset/collation.

6. Be sure the DB is not in use before running the script.

7. Run cdc.php, either in an editor or from the command line (php cdc.php). Do *not* run it as part of a CMS
   that uses the database.

8. Copy just the SQL query code to the clipboard. Make sure you get all of it.

9. InPhpMyAdmin, go to the [sitename]temp database. Click on the SQL tab at the top.

10. Paste in the SQL query code and click on "Go."

11. In the local PhpMyAdmin, export the [sitename]temp database to a file called after.sql

12. [optional] Use a diff program like WinMerge to compare the before.sql and after.sql files.
    (See the notes below.)

13. On the remote site, create a new database with the new charset and collation and "Import" the after.sql file.

14. Edit your CMS' config file to use the new charset and the new database.

15. Look at the front end for your database and check for anomalous characters.

NOTES: When you compare the before.sql and the after.sql files, you'll see that the order of some of the indexes
has changed. This is normal and unavoidable. As long as they're all there, it's not a problem. It happens
because the script has to drop some indexes and add them back. When they're added back, they go at the end
of the indexes.

It's fairly common to have some extraneous characters in your database before and after conversion.
Pasting text from Word or other Windows editors may give you some unusual apostrophes and quote marks (both
single and double). Words "curly" quotes (aka "smart quotes") are an example. Usually, you can leave these
alone since most modern browsers display them correctly. Generally speaking, you shouldn't edit anything
unless it causes trouble in the displayed page.

You may see odd characters as garbage at the end of some database fields. You can leave
them alone.

Sometimes you'll see extraneous characters that are not quotes or apostrophes -- most often, these are non-breaking
spaces. They look like a capital A with a hat (^). Usually, these can be left alone. Be careful if you delete or
replace them because they are a two-character sequence (C2 A0) and if you only delete one of them, the display will
break at that point. If you want them to be spaces, replace the C2 A0 with 20 (The space character).

Note that in an SQL file, an apostrophe is represented by two single quotes (e.g. can''t, don''t, won''t).
Don't alter these or your Import will crash.

NOTES FOR MODx USERS: Clear the site cache before starting. Before Exporting your database empty (truncate) the
error (modx_event_log) log and the manager action log (modx_manager_log) tables. After importing the converted
database, edit manager/includes/config.inc.php, or for MODx Revolution core/config/config.inc.php. Change the
charset to utf8 and change the name of the database to the new one. Important, be sure to clear the site cache
and your browser cache before testing the new site.

It's not a bad idea to do an upgrade install (be sure to uncheck all the options for installing snippets and
the sample site in MODx Evolution).

