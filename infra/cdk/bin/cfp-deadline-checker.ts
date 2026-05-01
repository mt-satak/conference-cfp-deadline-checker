#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { CfpDeadlineCheckerStack } from '../lib/cfp-deadline-checker-stack';

const app = new cdk.App();

new CfpDeadlineCheckerStack(app, 'CfpDeadlineCheckerStack', {
  env: {
    account: process.env.CDK_DEFAULT_ACCOUNT,
    region: process.env.CDK_DEFAULT_REGION ?? 'ap-northeast-1',
  },
  description: 'Conference CfP Deadline Checker - main stack',
});
