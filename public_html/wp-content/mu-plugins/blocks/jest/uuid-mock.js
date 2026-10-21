let uuidCounter = 0;

module.exports = {
	v4: () => {
		uuidCounter += 1;

		const paddedCounter = String( uuidCounter ).padStart( 12, '0' );

		return `00000000-0000-4000-8000-${ paddedCounter }`;
	},
};
