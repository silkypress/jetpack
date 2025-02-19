/**
 * Internal dependencies
 */
import { sendFailedTestScreenshotToSlack, sendFailedTestMessageToSlack } from './reporters/slack';
import { takeScreenshot } from './reporters/screenshot';

/**
 * Override the test case method so we can take screenshots of assertion failures.
 *
 * See: https://github.com/smooth-code/jest-puppeteer/issues/131#issuecomment-469439666
 */
let currentBlock;
const { CI, E2E_DEBUG } = process.env;

global.describe = ( name, func ) => {
	currentBlock = name;

	try {
		func();
	} catch ( e ) {
		throw e;
	}
};

global.it = async ( name, func ) => {
	return await test( name, async () => {
		try {
			await func();
		} catch ( error ) {
			// If running tests in CI
			if ( CI ) {
				const filePath = await takeScreenshot( currentBlock, name );
				await sendFailedTestMessageToSlack( { block: currentBlock, name, error } );
				await sendFailedTestScreenshotToSlack( filePath );
			}

			if ( E2E_DEBUG ) {
				console.log( error );
				await jestPuppeteer.debug();
			}

			throw error;
		}
	} );
};
